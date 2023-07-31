<?php

namespace Spectator;

use cebe\openapi\exceptions\IOException;
use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use cebe\openapi\json\InvalidJsonPointerSyntaxException;
use cebe\openapi\spec\PathItem;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\MalformedSpecException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Validation\RequestValidator;
use Spectator\Validation\ResponseValidator;

class Middleware
{
    /**
     * @var ExceptionHandler
     */
    protected ExceptionHandler $exceptionHandler;

    /**
     * @var RequestFactory
     */
    protected RequestFactory $spectator;

    /**
     * @var string
     */
    protected string $version = '3.0';

    /**
     * Middleware constructor.
     *
     * @param  RequestFactory  $spectator
     * @param  ExceptionHandler  $exceptionHandler
     */
    public function __construct(RequestFactory $spectator, ExceptionHandler $exceptionHandler)
    {
        $this->spectator = $spectator;
        $this->exceptionHandler = $exceptionHandler;
    }

    /**
     * @param  Request  $request
     * @param  Closure  $next
     * @return JsonResponse|Request
     *
     * @throws InvalidPathException
     * @throws MissingSpecException
     * @throws RequestValidationException
     * @throws \Throwable
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $this->spectator->getSpec()) {
            return $next($request);
        }

        try {
            $requestPath = $request->route()->uri();
            $pathItem = $this->pathItem($requestPath, $request->method());
        } catch (InvalidPathException|MalformedSpecException|MissingSpecException|TypeErrorException|UnresolvableReferenceException $exception) {
            $this->spectator->captureRequestValidation($exception);

            return $next($request);
        }

        try {
            return $this->validate($request, $next, $requestPath, $pathItem);
        } catch (\Throwable $exception) {
            if ($this->exceptionHandler->shouldReport($exception)) {
                return $this->formatResponse($exception, 500);
            }

            throw $exception;
        }
    }

    /**
     * @param  $exception
     * @param  $code
     * @return JsonResponse
     */
    protected function formatResponse($exception, $code): JsonResponse
    {
        $errors = method_exists($exception, 'getErrors')
            ? ['specErrors' => $exception->getErrors()]
            : [];

        return Response::json(array_merge([
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ], $errors), $code);
    }

    /**
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string  $requestPath
     * @param  PathItem  $pathItem
     * @return mixed
     */
    protected function validate(Request $request, Closure $next, string $requestPath, PathItem $pathItem)
    {
        try {
            RequestValidator::validate(
                $request,
                $pathItem,
                $request->method()
            );
        } catch (\Exception $exception) {
            $this->spectator->captureRequestValidation($exception);
        }

        $response = $next($request);

        try {
            ResponseValidator::validate(
                $requestPath,
                $response,
                $pathItem->{strtolower($request->method())},
                $this->version
            );
        } catch (\Exception $exception) {
            $this->spectator->captureResponseValidation($exception);
        }

        return $response;
    }

    /**
     * @param  $requestPath
     * @param  $requestMethod
     * @return PathItem
     *
     * @throws InvalidPathException
     * @throws MalformedSpecException
     * @throws MissingSpecException
     * @throws TypeErrorException
     * @throws UnresolvableReferenceException
     * @throws IOException
     * @throws InvalidJsonPointerSyntaxException
     */
    protected function pathItem($requestPath, $requestMethod): PathItem
    {
        if (! Str::startsWith($requestPath, '/')) {
            $requestPath = '/'.$requestPath;
        }

        $openapi = $this->spectator->resolve();

        $this->version = $openapi->openapi;

        foreach ($openapi->paths as $path => $pathItem) {
            if ($this->resolvePath($path) === $requestPath) {
                $methods = array_keys($pathItem->getOperations());

                // Check if the method exists for this path, and if so return the full PathItem
                if (in_array(strtolower($requestMethod), $methods, true)) {
                    return $pathItem;
                }

                throw new InvalidPathException("[{$requestMethod}] not a valid method for [{$requestPath}].", 405);
            }
        }

        throw new InvalidPathException("Path [{$requestMethod} {$requestPath}] not found in spec.", 404);
    }

    /**
     * @param  string  $path
     * @return string
     */
    protected function resolvePath(string $path): string
    {
        $separator = '/';

        $parts = array_filter(array_map(function ($part) use ($separator) {
            return trim($part, $separator);
        }, [$this->spectator->getPathPrefix(), $path]));

        return $separator.implode($separator, $parts);
    }
}
