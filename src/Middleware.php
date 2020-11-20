<?php

namespace Spectator;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Spectator\Validation\RequestValidator;
use Spectator\Validation\ResponseValidator;
use Spectator\Exceptions\InvalidPathException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\Exceptions\InvalidMethodException;
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

        try {
            $response = $this->validate($request, $next);
        } catch (RequestValidationException $exception) {
            return $this->formatResponse($exception, 400);
        } catch (ResponseValidationException $exception) {
            return $this->formatResponse($exception, 400);
        } catch (InvalidMethodException $exception) {
            return $this->formatResponse($exception, 405);
        } catch (InvalidPathException $exception) {
            return $this->formatResponse($exception, 422);
        } catch (MissingSpecException $exception) {
            return $this->formatResponse($exception, 500);
        } catch (UnresolvableReferenceException $exception) {
            return $this->formatResponse($exception, 500);
        } catch (\Throwable $exception) {
            return $this->formatResponse($exception, 500);
        }

        return $response;
    }

    protected function formatResponse($exception, $code)
    {
        $errors = method_exists($exception, 'getErrors')
            ? ['errors' => $exception->getErrors()]
            : [];

        return Response::json(array_merge([
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ], $errors), $code);
    }

    protected function validate(Request $request, Closure $next)
    {
        $request_path = $request->route()->uri();

        $operation = $this->operation($request_path, $request->method());

        RequestValidator::validate($request, $operation);

        $response = $next($request);

        ResponseValidator::validate($request_path, $response, $operation);

        $this->spectator->reset();

        return $response;
    }

    protected function operation($request_path, $request_method)
    {
        if (!Str::startsWith($request_path, '/')) {
            $request_path = '/'.$request_path;
        }

        $openapi = $this->spectator->resolve();

        foreach ($openapi->paths as $path => $pathItem) {
            if ($this->resolvePath($path) === $request_path) {
                $methods = array_keys($pathItem->getOperations());

                if (in_array(strtolower($request_method), $methods, true)) {
                    return $pathItem->getOperations()[strtolower($request_method)];
                }

                throw new InvalidPathException("[{$request_method}] not a valid method for [{$request_path}].", 405);
            }
        }

        throw new InvalidPathException("Path [{$request_method} {$request_path}] not found in spec.", 404);
    }

    protected function resolvePath($path)
    {
        $separator = '/';

        $parts = array_filter(array_map(function ($part) use ($separator) {
            return str_replace($separator, '', $part);
        }, [
            config('spectator.path_prefix'),
            $path,
        ]));

        return $separator.implode($separator, $parts);
    }
}
