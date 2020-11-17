<?php

namespace Spectator;

use Closure;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Response;
use Spectator\Validation\RequestValidator;
use Spectator\Validation\ResponseValidator;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\ResponseValidationException;
use cebe\openapi\exceptions\UnresolvableReferenceException;

class Middleware
{
    protected $spectator;

    public function __construct(RequestFactory $spectator)
    {
        $this->spectator = $spectator;
    }

    public function handle(Request $request, Closure $next)
    {
        if (!$this->spectator->getSpec()) {
            return $next($request);
        }

        $operation = $this->operation($request);

//        if ($invalid = $this->validateRequest($operation, $request)) {
//            return $invalid;
//        }

        $response = $next($request);

        if ($invalid = $this->validateResponse($request->route()->uri(), $operation, $response)) {
            return $invalid;
        }

        $this->spectator->reset();

        return $response;
    }

    protected function validateRequest($operation, $request)
    {
        try {
            RequestValidator::validate($request, $operation);
        } catch (UnresolvableReferenceException $exception) {
            return Response::json([
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ], 500);
        } catch (RequestValidationException $exception) {
            return Response::json([
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
            ], 400);
        } catch (Exception $exception) {
            throw $exception;
        }
    }

    protected function validateResponse(string $uri, $operation, $response)
    {
        try {
            ResponseValidator::validate($uri, $response, $operation);
        } catch (QueryException $exception) {
            throw $exception;
        } catch (UnresolvableReferenceException $exception) {
            return Response::json([
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ], 500);
        } catch (ResponseValidationException $exception) {
            return Response::json([
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
            ], 400);
        } catch (Exception $exception) {
            throw $exception;
        }
    }

    protected function operation(Request $request)
    {
        $openapi = $this->spectator->resolve();

        $request_path = $request->route()->uri();

        if (!Str::startsWith($request_path, '/')) {
            $request_path = '/'.$request_path;
        }

        $request_method = $request->method();

        foreach ($openapi->paths as $path => $pathItem) {
            if ($path === $request_path) {
                $methods = array_keys($pathItem->getOperations());

                if (in_array(strtolower($request_method), $methods, true)) {
                    return $pathItem->getOperations()[strtolower($request_method)];
                }

                throw new InvalidPathException("[{$request_method}] not a valid method for [{$request_path}].", 405);
            }
        }

        throw new InvalidPathException("Path [{$request_method} {$request_path}] not found in spec.", 404);
    }
}
