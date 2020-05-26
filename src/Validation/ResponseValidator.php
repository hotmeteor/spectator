<?php

namespace Spectator\Validation;

use JsonSchema\Validator;
use cebe\openapi\spec\Operation;
use Illuminate\Http\JsonResponse;
use Spectator\Exceptions\ResponseValidationException;

class ResponseValidator
{
    protected $response;

    protected $operation;

    public function __construct(JsonResponse $response, Operation $operation)
    {
        $this->response = $response;
        $this->operation = $operation;
    }

    public static function validate(JsonResponse $response, Operation $operation)
    {
        $instance = new self($response, $operation);

        $instance->handle();
    }

    protected function handle()
    {
        $contentType = $this->response->headers->get('Content-Type');
        $body = $this->response->getContent();
        $responses = $this->operation->responses;

        $shortHandler = class_basename($this->operation->operationId);

        // Get matching response object based on status code.
        if ($responses[$this->response->getStatusCode()] !== null) {
            $responseObject = $responses[$this->response->getStatusCode()];
        } elseif ($responses['default'] !== null) {
            $responseObject = $responses['default'];
        } else {
            throw new ResponseValidationException("No response object matching returned status code [{$this->response->getStatusCode()}].");
        }

        if (!array_key_exists($contentType, $responseObject->content)) {
            throw new ResponseValidationException('Response did not match any specified media type.');
        }

        $jsonSchema = $responseObject->content[$contentType]->schema;
        $validator = new Validator();

        if ($jsonSchema->type === 'object' || $jsonSchema->type === 'array') {
            if ($contentType === 'application/json') {
                $body = json_decode($body);
            } else {
                throw new ResponseValidationException("Unable to map [{$contentType}] to schema type [object].");
            }
        }

        $validator->validate($body, $jsonSchema->getSerializableData());

        if ($validator->isValid() !== true) {
            throw ResponseValidationException::withSchemaErrors("The response from {$shortHandler} does not match your OpenAPI specification.", $validator->getErrors());
        }
    }
}
