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
            $response = $this->validate($request, $next);
        } catch (InvalidPathException|MalformedSpecException|MissingSpecException|TypeErrorException|UnresolvableReferenceException $exception) {
            $this->spectator->captureRequestValidation($exception);
        } catch (\Throwable $exception) {
            if ($this->exceptionHandler->shouldReport($exception)) {
                return $this->formatResponse($exception, 500);
            }

            throw $exception;
        }

        return $response;
    }

    /**
     * @param $exception
     * @param $code
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
     * @return mixed
     *
     * @throws InvalidPathException
     * @throws MalformedSpecException
     * @throws MissingSpecException
     * @throws TypeErrorException
     * @throws UnresolvableReferenceException
     * @throws IOException
     * @throws InvalidJsonPointerSyntaxException
     */
    protected function validate(Request $request, Closure $next)
    {
        $request_path = $request->route()->uri();

        $pathItem = $this->pathItem($request_path, $request->method());

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
                $request_path,
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
     * @param $request_path
     * @param $request_method
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
    protected function pathItem($request_path, $request_method): PathItem
    {
        if (! Str::startsWith($request_path, '/')) {
            $request_path = '/'.$request_path;
        }

        $openapi = $this->spectator->resolve();

        $this->version = $openapi->openapi;

        foreach ($openapi->paths as $path => $pathItem) {
            if ($this->resolvePath($path) === $request_path) {
                $methods = array_keys($pathItem->getOperations());

                // Check if the method exists for this path, and if so return the full PathItem
                if (in_array(strtolower($request_method), $methods, true)) {
                    return $pathItem;
                }

                throw new InvalidPathException("[{$request_method}] not a valid method for [{$request_path}].", 405);
            }
        }

        throw new InvalidPathException("Path [{$request_method} {$request_path}] not found in spec.", 404);
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
