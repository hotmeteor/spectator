<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\PathItem;
use Illuminate\Http\Request;
use Opis\JsonSchema\Validator;
use Spectator\Exceptions\RequestValidationException;

class RequestValidator extends AbstractValidator
{
    protected $request;

    protected $pathItem;

    protected $method;

    public function __construct(Request $request, PathItem $pathItem, $method, $version = '3.0')
    {
        $this->request = $request;
        $this->pathItem = $pathItem;
        $this->method = strtolower($method);
        $this->version = $version;
    }

    public static function validate(Request $request, PathItem $pathItem, $method)
    {
        $instance = new self($request, $pathItem, $method);

        $instance->handle();
    }

    protected function handle()
    {
        $this->validateParameters();

        if ($this->operation()->requestBody !== null) {
            $this->validateBody();
        }
    }

    protected function validateParameters()
    {
        $route = $this->request->route();
        $parameters = $this->pathItem->parameters;

        foreach ($parameters as $parameter) {

            // Verify presence, if required.
            if ($parameter->required === true) {
                // Parameters can be found in query, header, path or cookie.
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

            // Validate schemas, if provided. Required or not.
            if ($parameter->schema) {
                $validator = new Validator();

                $jsonSchema = $parameter->schema->getSerializableData();

                $result = null;

                if ($parameter->in === 'path' && $route->hasParameter($parameter->name)) {
                    $data = $route->parameters();
                    $result = $validator->validate($data[$parameter->name], $jsonSchema);
                } elseif ($parameter->in === 'query' && $this->request->query->has($parameter->name)) {
                    $data = $this->request->query->get($parameter->name);
                    $result = $validator->validate($data, $jsonSchema);
                } elseif ($parameter->in === 'header' && $this->request->headers->has($parameter->name)) {
                    $data = $this->request->headers->get($parameter->name);
                    $result = $validator->validate($data, $jsonSchema);
                } elseif ($parameter->in === 'cookie' && $this->request->cookies->has($parameter->name)) {
                    $data = $this->request->cookies->get($parameter->name);
                    $result = $validator->validate($data, $jsonSchema);
                }

                if (optional($result)->isValid() === false) {
                    throw RequestValidationException::withError("Parameter [{$parameter->name}] did not match provided JSON schema.", $result->error());
                }
            }
        }
    }

    protected function validateBody()
    {
        $contentType = $this->request->header('Content-Type');
        $body = $this->request->getContent();
        $requestBody = $this->operation()->requestBody;

        if ($requestBody->required === true) {
            if (empty($body)) {
                throw new RequestValidationException('Request body required.');
            }
        }

        if (empty($this->request->getContent())) {
            return;
        }

        if (! array_key_exists($contentType, $requestBody->content)) {
            throw new RequestValidationException('Request did not match any specified media type for request body.');
        }

        $jsonSchema = $requestBody->content[$contentType]->schema;
        $validator = new Validator();

        if ($jsonSchema->type === 'object' || $jsonSchema->type === 'array') {
            if (in_array($contentType, ['application/json', 'application/vnd.api+json'])) {
                $body = json_decode($body);
            } else {
                throw new RequestValidationException("Unable to map [{$contentType}] to schema type [object].");
            }
        }

        $result = $validator->validate($body, $this->prepareData($jsonSchema->getSerializableData()));

        if (! $result->isValid()) {
            throw RequestValidationException::withError('Request body did not match provided JSON schema.', $result->error());
        }
    }

    protected function operation(): Operation
    {
        return $this->pathItem->{$this->method};
    }
}
