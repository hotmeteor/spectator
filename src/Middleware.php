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
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\MalformedSpecException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\ResponseValidationException;
use Spectator\Validation\RequestValidator;
use Spectator\Validation\ResponseValidator;
use Throwable;

class Middleware
{
    protected ExceptionHandler $exceptionHandler;

    protected RequestFactory $spectator;

    protected string $version = '3.0';

    public function __construct(RequestFactory $spectator, ExceptionHandler $exceptionHandler)
    {
        $this->spectator = $spectator;
        $this->exceptionHandler = $exceptionHandler;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->spectator->getSpec()) {
            return $next($request);
        }

        try {
            /** @var \Illuminate\Routing\Route $route */
            $route = $request->route();
            [$specPath, $pathItem] = $this->pathItem($route, $request->method());
        } catch (InvalidPathException|MalformedSpecException|MissingSpecException|TypeErrorException|UnresolvableReferenceException $exception) {
            $this->spectator->captureRequestValidation($exception);
            $this->spectator->captureResponseValidation($exception);

            return $next($request);
        }

        try {
            return $this->validate($request, $next, $specPath, $pathItem);
        } catch (Throwable $exception) {
            if ($this->exceptionHandler->shouldReport($exception)) {
                return $this->formatResponse($exception, 500);
            }

            throw $exception;
        }
    }

    protected function formatResponse(Throwable $exception, int $code): JsonResponse
    {
        $errors = method_exists($exception, 'getErrors')
            ? ['specErrors' => $exception->getErrors()]
            : [];

        return Response::json([
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            ...$errors,
        ], $code);
    }

    protected function validate(Request $request, Closure $next, string $specPath, PathItem $pathItem): mixed
    {
        try {
            RequestValidator::validate(
                $request,
                $specPath,
                $pathItem,
                $this->version
            );
        } catch (RequestValidationException $exception) {
            $this->spectator->captureRequestValidation($exception);
        }

        $response = $next($request);

        try {
            ResponseValidator::validate(
                $response,
                $pathItem->{strtolower($request->method())},
                $this->version
            );
        } catch (ResponseValidationException $exception) {
            $this->spectator->captureResponseValidation($exception);
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: PathItem}
     *
     * @throws InvalidPathException
     * @throws MalformedSpecException
     * @throws MissingSpecException
     * @throws TypeErrorException
     * @throws UnresolvableReferenceException
     * @throws IOException
     * @throws InvalidJsonPointerSyntaxException
     */
    protected function pathItem(Route $route, string $requestMethod): array
    {
        $requestPath = Str::start($route->uri(), '/');

        $openapi = $this->spectator->resolve();

        $this->version = $openapi->openapi;

        $pathMatches = false;
        $partialMatch = null;

        foreach ($openapi->paths as $path => $pathItem) {
            $resolvedPath = $this->resolvePath($path);
            $methods = array_keys($pathItem->getOperations());

            if ($resolvedPath === $requestPath) {
                $pathMatches = true;
                // Check if the method exists for this path, and if so return the full PathItem
                if (in_array(strtolower($requestMethod), $methods, true)) {
                    return [$resolvedPath, $pathItem];
                }
            }

            if (Str::match($route->getCompiled()->getRegex(), $resolvedPath) !== '') {
                $pathMatches = true;
                if (in_array(strtolower($requestMethod), $methods, true)) {
                    $partialMatch = [$resolvedPath, $pathItem];
                }
            }
        }

        if ($partialMatch !== null) {
            return $partialMatch;
        }

        throw $pathMatches
            ? throw new InvalidPathException("[{$requestMethod}] not a valid method for [{$requestPath}].", 405)
            : new InvalidPathException("Path [{$requestMethod} {$requestPath}] not found in spec.", 404);
    }

    protected function resolvePath(string $path): string
    {
        $separator = '/';

        $parts = array_filter(array_map(function ($part) use ($separator) {
            return trim($part, $separator);
        }, [$this->spectator->getPathPrefix(), $path]));

        return $separator.implode($separator, $parts);
    }
}
