<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\PathItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Opis\JsonSchema\Validator;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\SchemaValidationException;
use stdClass;

class RequestValidator extends AbstractValidator
{
    /**
     * RequestValidator constructor.
     */
    public function __construct(
        protected Request $request,
        protected string $specPath,
        protected PathItem $pathItem,
        string $version = '3.0'
    ) {
        $this->version = $version;
    }

    /**
     * @throws RequestValidationException|SchemaValidationException
     */
    public static function validate(Request $request, string $specPath, PathItem $pathItem, string $version): void
    {
        $instance = new self($request, $specPath, $pathItem, $version);

        $instance->handle();
    }

    /**
     * @throws RequestValidationException|SchemaValidationException
     */
    protected function handle(): void
    {
        $this->validateParameters();

        if ($this->operation()->requestBody !== null) {
            $this->validateBody();
        }
    }

    /**
     * @throws RequestValidationException|SchemaValidationException
     */
    protected function validateParameters(): void
    {
        /** @var \Illuminate\Routing\Route $route */
        $route = $this->request->route();

        $parameters = array_merge(
            $this->pathItem->parameters,
            $this->operation()->parameters
        );

        $requiredParameters = array_filter($parameters, fn ($parameter) => $parameter->required === true);

        foreach ($requiredParameters as $parameter) {
            // Verify presence, if required.
            if ($parameter->in === 'path' && ! $route->hasParameter($this->translateParameterName($parameter->name))) {
                throw new RequestValidationException("Missing required parameter {$parameter->name} in URL path.");
            } elseif ($parameter->in === 'query' && ! $this->hasQueryParam($parameter->name)) {
                throw new RequestValidationException("Missing required query parameter [?{$parameter->name}=].");
            } elseif ($parameter->in === 'header' && ! $this->request->headers->has($parameter->name)) {
                throw new RequestValidationException("Missing required header [{$parameter->name}].");
            } elseif ($parameter->in === 'cookie' && ! $this->request->cookies->has($parameter->name)) {
                throw new RequestValidationException("Missing required cookie [{$parameter->name}].");
            }
        }

        foreach ($parameters as $parameter) {
            // Validate schemas, if provided.
            if ($parameter->schema) {
                $validator = new Validator;
                $expectedParameterSchema = $parameter->schema->getSerializableData();
                $result = null;
                $parameterValue = null;

                // Get parameter, then validate it.
                if ($parameter->in === 'path' && $route->hasParameter($parameterName = $this->translateParameterName($parameter->name))) {
                    $parameterValue = $route->parameters()[$parameterName];
                    if ($parameterValue instanceof Model) {
                        $parameterValue = $route->originalParameters()[$parameterName];
                    } elseif ($parameterValue instanceof \BackedEnum) {
                        $parameterValue = $route->originalParameters()[$parameterName];
                    }
                } elseif ($parameter->in === 'query' && $this->hasQueryParam($parameter->name)) {
                    $parameterValue = $this->getQueryParam($parameter->name);

                    if ($parameter->explode === false && $parameter->schema->type === 'array') {
                        $parameterValue = explode(',', $parameterValue);
                    }
                } elseif ($parameter->in === 'header' && $this->request->headers->has($parameter->name)) {
                    $parameterValue = $this->request->headers->get($parameter->name);
                } elseif ($parameter->in === 'cookie' && $this->request->cookies->has($parameter->name)) {
                    $parameterValue = $this->request->cookies->get($parameter->name);
                }

                if ($parameterValue) {
                    $parameterValue = $this->castParameterValue($parameterValue, $expectedParameterSchema);

                    $result = $validator->validate($this->toObject($parameterValue), $expectedParameterSchema);

                    // If the result is not valid, then display failure reason.
                    if ($result->isValid() === false) {
                        $message = RequestValidationException::validationErrorMessage($expectedParameterSchema,
                            $result->error());

                        throw RequestValidationException::withError($message, $result->error());
                    }
                }
            }
        }
    }

    protected function translateParameterName(string $parameter): string
    {
        /** @var \Illuminate\Routing\Route $route */
        $route = $this->request->route();

        preg_match($route->getCompiled()->getRegex(), $this->specPath, $matches);

        return Collection::make($matches)
            ->filter(fn (string $value, int|string $key) => is_string($key))
            ->flip()
            ->get("{{$parameter}}", $parameter);
    }

    /**
     * @throws RequestValidationException|SchemaValidationException
     */
    protected function validateBody(): void
    {
        $expectedBody = $this->operation()->requestBody;

        // Content types should match.
        $contentType = $this->request->header('Content-Type');
        if (! array_key_exists($contentType, $expectedBody->content)) {
            throw new RequestValidationException('Request did not match any specified media type for request body.');
        }

        // Capture schemas for validation.
        $expectedBodyRawSchema = $expectedBody->content[$contentType]->schema;
        if (
            ($expectedBodyRawSchema->type === 'object' || $expectedBodyRawSchema->type === 'array' || $expectedBodyRawSchema->oneOf || $expectedBodyRawSchema->anyOf)
            && in_array($contentType, ['application/json', 'application/vnd.api+json'])
        ) {
            $actualBodySchema = json_decode($this->request->getContent());
        } else {
            $actualBodySchema = $this->parseBodySchema();
        }

        // If required, then body should be non-empty.
        if ($expectedBody->required === true && empty($actualBodySchema)) {
            throw new RequestValidationException('Request body required!');
        }

        $expectedBodySchema = $this->prepareData($expectedBodyRawSchema, 'write');

        // Run validation.
        $validator = new Validator;
        $result = $validator->validate($actualBodySchema, $expectedBodySchema);

        // If the result is not valid, then display failure reason.
        if ($result->isValid() === false) {
            $message = RequestValidationException::validationErrorMessage($expectedBodySchema, $result->error());

            throw RequestValidationException::withError($message, $result->error());
        }
    }

    protected function operation(): Operation
    {
        return $this->pathItem->{strtolower($this->request->method())};
    }

    protected function parseBodySchema(): stdClass
    {
        $body = $this->request->all();

        array_walk_recursive($body, function (&$value) {
            if ($value instanceof UploadedFile) {
                $value = $value->get();
            }
        });

        return $this->toObject($body);
    }

    private function castParameterValue(mixed $parameterValue, ?stdClass $expectedSchema): mixed
    {
        if ($expectedSchema === null || ! isset($expectedSchema->type)) {
            return $parameterValue;
        }

        if ($expectedSchema->type === 'integer' && is_numeric($parameterValue)) {
            return (int) $parameterValue;
        } elseif ($expectedSchema->type === 'number' && is_numeric($parameterValue)) {
            return (float) $parameterValue;
        } elseif ($expectedSchema->type === 'object' && Arr::isAssoc($parameterValue)) {
            return Arr::map(
                $parameterValue,
                fn (mixed $value, string $key) => $this->castParameterValue($value, $expectedSchema->properties?->{$key})
            );
        } elseif ($expectedSchema->type === 'array' && is_array($parameterValue)) {
            return Arr::map(
                $parameterValue,
                fn (mixed $value) => $this->castParameterValue($value, $expectedSchema->items)
            );
        }

        return $parameterValue;
    }

    /**
     * @return ($data is array<string, mixed> ? \stdClass : ($data is array<int, mixed> ? array<int, mixed> : mixed))
     */
    private function toObject(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        } elseif (Arr::isAssoc($data)) {
            return (object) array_map([$this, 'toObject'], $data);
        } else {
            return array_map([$this, 'toObject'], $data);
        }
    }

    private function hasQueryParam(string $parameterName): bool
    {
        return Arr::has($this->request->query->all(), $this->convertQueryParameterToDotted($parameterName));
    }

    /**
     * @return string|array<array-key, mixed>|null
     */
    private function getQueryParam(string $parameterName): string|array|null
    {
        return Arr::get($this->request->query->all(), $this->convertQueryParameterToDotted($parameterName));
    }

    private function convertQueryParameterToDotted(string $parameterName): string
    {
        parse_str($parameterName, $parsedParameterName);

        return key(Arr::dot($parsedParameterName));
    }
}
