<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Spectator\Exceptions\ResponseValidationException;

class ResponseValidator extends AbstractValidator
{
    protected $uri;

    protected $response;

    protected $operation;

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

        // does the response match any of the specified media types?
        $response_type_match = false;
        if (! array_key_exists($contentType, $response->content)) {
            $response_type_match = true;
        }

        $schema = $response->content[$contentType]->schema;

        if (! $response_type_match) {
            $message = 'Response did not match any specified content type.';
            $message .= PHP_EOL.PHP_EOL.'  Expected: '.$contentType;
            $message .= PHP_EOL.'  Actual: DNE';
            $message .= PHP_EOL.PHP_EOL.'  ---';
            throw new ResponseValidationException($message);
        }

        $this->validateResponse(
            $schema,
            $this->body($contentType, $this->schemaType($schema))
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

        $actual_response = $body;
        $body = json_decode($body);

        $expected_schema = $this->prepareData($schema);
        $expected_response = json_encode($expected_schema);

        $result = $validator->validate($body, $expected_schema);

        if ($result instanceof ValidationResult && $result->isValid() === false) {
            $message = 'Error (Opis\JsonSchema\Validator): '.$result->error()->message();

            $message .= PHP_EOL.PHP_EOL.'  Keyword: '.$result->error()->keyword();
            $message .= PHP_EOL.'  Expected: '.$expected_response;
            $message .= PHP_EOL.'  Actual: '.$actual_response;
            $message .= PHP_EOL.PHP_EOL.'  ---';

            throw ResponseValidationException::withError($message, $result->error());
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
     * @return ?string
     */
    protected function schemaType(Schema $schema)
    {
        if ($schema->type) {
            return $schema->type;
        }

        if ($schema->allOf) {
            return 'allOf';
        }

        if ($schema->anyOf) {
            return 'anyOf';
        }

        if ($schema->oneOf) {
            return 'oneOf';
        }

        return null;
    }

    /**
     * @param $contentType
     * @param $schemaType
     * @return mixed
     *
     * @throws ResponseValidationException
     */
    protected function body($contentType, $schemaType)
    {
        $body = $this->response->getContent();

        if (in_array($schemaType, ['object', 'array', 'allOf'], true)) {
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
