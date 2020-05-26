<?php

namespace Spectator\Validation;

use JsonSchema\Validator;
use Illuminate\Http\Request;
use cebe\openapi\spec\Operation;
use Spectator\Exceptions\RequestValidationException;

class RequestValidator
{
    protected $request;

    protected $operation;

    public function __construct(Request $request, Operation $operation)
    {
        $this->request = $request;
        $this->operation = $operation;
    }

    public static function validate(Request $request, Operation $operation)
    {
        $instance = new self($request, $operation);

        $instance->handle();
    }

    protected function handle()
    {
        $this->validateParameters();

        if ($this->operation->requestBody !== null) {
            $this->validateBody();
        }
    }

    protected function validateParameters()
    {
        $route = $this->request->route();
        $parameters = $this->operation->parameters;

        foreach ($parameters as $parameter) {
            // Verify presence, if required.
            if ($parameter->required === true) {
                // Parameters can be found in query, header, path or cookie.
                if ($parameter->in === 'path' && !$route->hasParameter($parameter->name)) {
                    throw new RequestValidationException("Missing required parameter {$parameter->name} in URL path.");
                } elseif ($parameter->in === 'query' && !$this->request->query->has($parameter->name)) {
                    throw new RequestValidationException("Missing required query parameter [?{$parameter->name}=].");
                } elseif ($parameter->in === 'header' && !$this->request->headers->has($parameter->name)) {
                    throw new RequestValidationException("Missing required header [{$parameter->name}].");
                } elseif ($parameter->in === 'cookie' && !$this->request->cookies->has($parameter->name)) {
                    throw new RequestValidationException("Missing required cookie [{$parameter->name}].");
                }
            }

            // Validate schemas, if provided. Required or not.
            if ($parameter->schema) {
                $validator = new Validator();
                $jsonSchema = $parameter->schema->getSerializableData();

                if ($parameter->in === 'path' && $route->hasParameter($parameter->name)) {
                    $data = $route->parameters();
                    $validator->validate($data[$parameter->name], $jsonSchema);
                } elseif ($parameter->in === 'query' && $this->request->query->has($parameter->name)) {
                    $data = $this->request->query->get($parameter->name);
                    $validator->validate($data, $jsonSchema);
                } elseif ($parameter->in === 'header' && $this->request->headers->has($parameter->name)) {
                    $data = $this->request->headers->get($parameter->name);
                    $validator->validate($data, $jsonSchema);
                } elseif ($parameter->in === 'cookie' && $this->request->cookies->has($parameter->name)) {
                    $data = $this->request->cookies->get($parameter->name);
                    $validator->validate($data, $jsonSchema);
                }

                if (!$validator->isValid()) {
                    throw RequestValidationException::withSchemaErrors("Parameter [{$parameter->name}] did not match provided JSON schema.", $validator->getErrors());
                }
            }
        }
    }

    protected function validateBody()
    {
        $contentType = $this->request->header('Content-Type');
        $body = $this->request->getContent();
        $requestBody = $this->operation->requestBody;

        if ($requestBody->required === true) {
            if (empty($body)) {
                throw new RequestValidationException('Request body required.');
            }
        }

        if (empty($this->request->getContent())) {
            return;
        }

        if (!array_key_exists($contentType, $requestBody->content)) {
            throw new RequestValidationException('Request did not match any specified media type for request body.');
        }

        $jsonSchema = $requestBody->content[$contentType]->schema;
        $validator = new Validator();

        if ($jsonSchema->type === 'object' || $jsonSchema->type === 'array') {
            if ($contentType === 'application/json') {
                $body = json_decode($body);
            } else {
                throw new RequestValidationException("Unable to map [{$contentType}] to schema type [object].");
            }
        }

        $validator->validate($body, $jsonSchema->getSerializableData());

        if ($validator->isValid() !== true) {
            throw RequestValidationException::withSchemaErrors('Request body did not match provided JSON schema.', $validator->getErrors());
        }
    }
}
