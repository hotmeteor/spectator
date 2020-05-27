<?php

namespace Spectator;

use Closure;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Spectator\Validation\RequestValidator;
use Spectator\Validation\ResponseValidator;
use Illuminate\Contracts\Debug\ExceptionHandler;
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

    public function handle($request, Closure $next)
    {
        if (!$this->spectator->getSpec()) {
            return $next($request);
        }

        try {
            $operation = $this->operation($request);

            RequestValidator::validate($request, $operation);

            $response = $next($request);

            ResponseValidator::validate($response, $operation);
        } catch (UnresolvableReferenceException $exception) {
            return Response::json([
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ], 500);
        } catch (RequestValidationException | ResponseValidationException $exception) {
            return Response::json([
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors(),
            ], 400);
        } catch (Exception $exception) {
            throw $exception;
        }

        $this->spectator->reset();

        return $response;
    }

    protected function operation(Request $request)
    {
        $openapi = $this->spectator->resolve();

        foreach ($openapi->paths as $path => $pathItem) {
            $request_path = $request->path();

            if (!Str::startsWith($request_path, '/')) {
                $request_path = '/'.$request_path;
            }

            if ($path === $request_path) {
                $methods = array_keys($pathItem->getOperations());

                if (in_array(strtolower($request->method()), $methods, true)) {
                    return $pathItem->getOperations()[strtolower($request->method())];
                }
            }
        }

        abort(405);
    }

    protected function handleException($passable, Exception $e)
    {
        if (!$this->container->bound(ExceptionHandler::class) || !$passable instanceof Request) {
            throw $e;
        }

        $handler = $this->container->make(ExceptionHandler::class);

        $handler->report($e);

        return $handler->render($passable, $e);
    }
}
