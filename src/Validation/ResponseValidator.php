<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Spectator\Exceptions\ResponseValidationException;

class ResponseValidator
{
    protected $uri;

    protected $response;

    protected $operation;

    protected $version;

    public function __construct(string $uri, $response, Operation $operation, $version = '3.0')
    {
        $this->uri = $uri;
        $this->response = $response;
        $this->operation = $operation;
        $this->version = $version;
    }

    public static function validate(string $uri, $response, Operation $operation, $version = '3.0')
    {
        $instance = new self($uri, $response, $operation, $version);

        $instance->handle();
    }

    /**
     * @throws ResponseValidationException
     */
    protected function handle()
    {
        $responseObject = $this->response();

        if ($responseObject->content) {
            $this->parseResponse($responseObject);
        }
    }

    /**
     * @throws ResponseValidationException
     */
    protected function parseResponse(Response $response)
    {
        $contentType = $this->contentType();

        if (! array_key_exists($contentType, $response->content)) {
            throw new ResponseValidationException('Response did not match any specified media type.');
        }

        $schema = $response->content[$contentType]->schema;

        $this->validateResponse(
            $schema,
            $this->body($contentType, $schema->type)
        );
    }

    /**
     * @param $body
     *
     * @throws ResponseValidationException
     */
    protected function validateResponse(Schema $schema, $body)
    {
        $validator = $this->validator();
        $shortHandler = $this->shortHandler();

        $result = $validator->validate($body, $this->prepareData($schema->getSerializableData()));

        if ($result instanceof ValidationResult && $result->isValid() === false) {
            $error = $this->firstError($result->error());
            $args = json_encode($error->args());
            $dataPointer = implode('.', $error->data()->path());
            throw ResponseValidationException::withError("{$shortHandler} json response field {$dataPointer} does not match the spec: [ {$error->keyword()}: {$args} ]", $error->message());
        }
    }

    /**
     * @throws ResponseValidationException
     */
    protected function response(): Response
    {
        $responses = $this->operation->responses;

        if ($responses[$this->response->getStatusCode()] !== null) {
            return $responses[$this->response->getStatusCode()];
        }

        if ($responses['default'] !== null) {
            return $responses['default'];
        }

        throw new ResponseValidationException("No response object matching returned status code [{$this->response->getStatusCode()}].");
    }

    /**
     * @return string
     */
    protected function contentType()
    {
        return $this->response->headers->get('Content-Type');
    }

    /**
     * @param $contentType
     * @param $schemaType
     *
     * @return mixed
     *
     * @throws ResponseValidationException
     */
    protected function body($contentType, $schemaType)
    {
        $body = $this->response->getContent();

        if (in_array($schemaType, ['object', 'array'], true)) {
            if (in_array($contentType, ['application/json', 'application/vnd.api+json'])) {
                return json_decode($body);
            } else {
                throw new ResponseValidationException("Unable to map [{$contentType}] to schema type [object].");
            }
        }

        return $body;
    }

    /**
     * @return string
     */
    protected function shortHandler()
    {
        return class_basename($this->operation->operationId) ?: $this->uri;
    }

    protected function validator(): Validator
    {
        $validator = new Validator();

        return $validator;
    }

    protected function prepareData($data)
    {
        if (! isset($data->properties)) {
            return $data;
        }

        $clone = clone $data;

        $v30 = Str::startsWith($this->version, '3.0');

        if ($v30) {
            $clone->properties = $this->wrapAttributesToArray($clone->properties);
        }

        return $clone;
    }

    protected function wrapAttributesToArray($properties)
    {
        foreach ($properties as $key => $attributes) {
            if (isset($attributes->nullable)) {
                $type = Arr::wrap($attributes->type);
                $type[] = 'null';
                $attributes->type = array_unique($type);
                unset($attributes->nullable);
            }

            if ($attributes->type === 'object' && isset($attributes->properties)) {
                $attributes->properties = $this->wrapAttributesToArray($attributes->properties);
            }

            if (
                $attributes->type === 'array'
                && isset($attributes->items)
                && isset($attributes->items->properties)
                && $attributes->items->type === 'object'
            ) {
                $attributes->items->properties = $this->wrapAttributesToArray($attributes->items->properties);
            }
        }

        return $properties;
    }

    protected function firstError(ValidationError $error): ValidationError
    {
        if (count($error->subErrors())) {
            return $this->firstError($error->subErrors()[0]);
        }

        return $error;
    }

    protected function arrayKeysRecursive($array): array
    {
        $flat = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $flat = array_merge($flat, $this->arrayKeysRecursive($value));
            } else {
                $flat[] = $key;
            }
        }

        return $flat;
    }
}
