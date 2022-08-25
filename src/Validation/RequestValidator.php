<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\PathItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Opis\JsonSchema\Validator;
use Spectator\Exceptions\RequestValidationException;
use Spectator\Exceptions\SchemaValidationException;

class RequestValidator extends AbstractValidator
{
    /**
     * @var Request
     */
    protected Request $request;

    /**
     * @var PathItem
     */
    protected PathItem $pathItem;

    /**
     * @var string
     */
    protected string $method;

    /**
     * @var array
     */
    protected array $parameters;

    /**
     * RequestValidator constructor.
     *
     * @param  Request  $request
     * @param  PathItem  $pathItem
     * @param  string  $method
     * @param  string  $version
     */
    public function __construct(Request $request, PathItem $pathItem, string $method, string $version = '3.0')
    {
        $this->request = $request;
        $this->pathItem = $pathItem;
        $this->method = strtolower($method);
        $this->version = $version;
    }

    /**
     * @param  Request  $request
     * @param  PathItem  $pathItem
     * @param  string  $method
     *
     * @throws RequestValidationException|SchemaValidationException
     */
    public static function validate(Request $request, PathItem $pathItem, string $method)
    {
        $instance = new self($request, $pathItem, $method);

        $instance->handle();
    }

    /**
     * @throws RequestValidationException|SchemaValidationException
     */
    protected function handle()
    {
        $this->validateParameters();

        if ($this->operation()->requestBody !== null) {
            $this->validateBody();
        }
    }

    /**
     * @throws RequestValidationException|SchemaValidationException
     */
    protected function validateParameters()
    {
        $route = $this->request->route();

        $parameters = array_merge(
            $this->pathItem->parameters,
            $this->operation()->parameters
        );

        $required_parameters = array_filter($parameters, fn ($parameter) => $parameter->required === true);

        foreach ($required_parameters as $parameter) {
            // Verify presence, if required.
            if ($parameter->in === 'path' && ! $route->hasParameter($parameter->name)) {
                throw new RequestValidationException("Missing required parameter {$parameter->name} in URL path.");
            } elseif ($parameter->in === 'query' && ! $this->request->query->has($parameter->name)) {
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
                $validator = new Validator();
                $expected_parameter_schema = $parameter->schema->getSerializableData();
                $result = null;
                $parameter_value = null;

                // Get parameter, then validate it.
                if ($parameter->in === 'path' && $route->hasParameter($parameter->name)) {
                    $parameter_value = $route->parameters()[$parameter->name];
                    if ($parameter_value instanceof Model) {
                        $parameter_value = $route->originalParameters()[$parameter->name];
                    }
                } elseif ($parameter->in === 'query' && $this->request->query->has($parameter->name)) {
                    $parameter_value = $this->request->query->get($parameter->name);
                } elseif ($parameter->in === 'header' && $this->request->headers->has($parameter->name)) {
                    $parameter_value = $this->request->headers->get($parameter->name);
                } elseif ($parameter->in === 'cookie' && $this->request->cookies->has($parameter->name)) {
                    $parameter_value = $this->request->cookies->get($parameter->name);
                }

                if ($parameter_value) {
                    if ($expected_parameter_schema->type && gettype($parameter_value) !== $expected_parameter_schema->type) {
                        $expected_type = $expected_parameter_schema->type;

                        if ($expected_type === 'number') {
                            $expected_type = is_float($parameter_value) ? 'float' : 'int';
                        }

                        settype($parameter_value, $expected_type);
                    }

                    $result = $validator->validate($parameter_value, $expected_parameter_schema);

                    // If the result is not valid, then display failure reason.
                    if ($result->isValid() === false) {
                        $message = RequestValidationException::validationErrorMessage($expected_parameter_schema, $result->error());
                        throw RequestValidationException::withError($message, $result->error());
                    }
                }
            }
        }
    }

    /**
     * @throws RequestValidationException|SchemaValidationException
     */
    protected function validateBody(): void
    {
        $expected_body = $this->operation()->requestBody;
        $actual_body = $this->request->getContent();

        // If required, then body should be non-empty.
        if ($expected_body->required === true && empty($actual_body)) {
            throw new RequestValidationException('Request body required.');
        }

        // Content types should match.
        $content_type = $this->request->header('Content-Type');
        if (! array_key_exists($content_type, $expected_body->content)) {
            throw new RequestValidationException('Request did not match any specified media type for request body.');
        }

        // Capture schemas for validation.
        $expected_body_raw_schema = $expected_body->content[$content_type]->schema;
        $actual_body_schema = $actual_body;
        if ($expected_body_raw_schema->type === 'object' || $expected_body_raw_schema->type === 'array' || $expected_body_raw_schema->oneOf || $expected_body_raw_schema->anyOf) {
            if (in_array($content_type, ['application/json', 'application/vnd.api+json'])) {
                $actual_body_schema = json_decode($actual_body_schema);
            } else {
                $actual_body_schema = $this->parseBodySchema();
            }
        }
        $expected_body_schema = $this->prepareData($expected_body_raw_schema);

        // Run validation.
        $validator = new Validator();
        $result = $validator->validate($actual_body_schema, $expected_body_schema);

        // If the result is not valid, then display failure reason.
        if ($result->isValid() === false) {
            $message = RequestValidationException::validationErrorMessage($expected_body_schema, $result->error());
            throw RequestValidationException::withError($message, $result->error());
        }
    }

    /**
     * @return Operation
     */
    protected function operation(): Operation
    {
        return $this->pathItem->{$this->method};
    }

    protected function parseBodySchema(): object
    {
        $body = $this->request->all();

        array_walk_recursive($body, function (&$value) {
            if ($value instanceof UploadedFile) {
                $value = $value->get();
            }
        });

        return $this->toObject($body);
    }

    private function toObject($data)
    {
        if (! is_array($data)) {
            return $data;
        } elseif (Arr::isAssoc($data)) {
            return (object) array_map([$this, 'toObject'], $data);
        } else {
            return array_map([$this, 'toObject'], $data);
        }
    }
}
