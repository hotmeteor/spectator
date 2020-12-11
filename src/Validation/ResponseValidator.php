<?php

namespace Spectator\Validation;

use Exception;
use cebe\openapi\spec\Schema;
use Opis\JsonSchema\Validator;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Operation;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Exception\SchemaKeywordException;
use Spectator\Exceptions\ResponseValidationException;

class ResponseValidator
{
    protected $uri;

    protected $response;

    protected $operation;

    public function __construct(string $uri, $response, Operation $operation)
    {
        $this->uri = $uri;
        $this->response = $response;
        $this->operation = $operation;
    }

    public static function validate(string $uri, $response, Operation $operation)
    {
        $instance = new self($uri, $response, $operation);

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

        if (!array_key_exists($contentType, $response->content)) {
            throw new ResponseValidationException('Response did not match any specified media type.');
        }

        $schema = $response->content[$contentType]->schema;

        $this->validateResponse(
            $schema, $this->body($contentType, $schema->type)
        );
    }

    /**
     * @param $body
     *
     * @throws ResponseValidationException
     */
    protected function validateResponse(Schema $schema, $body)
    {
        $result = null;
        $validator = $this->validator();
        $shortHandler = $this->shortHandler();

        try {
            $result = $validator->dataValidation($body, $schema->getSerializableData(), -1);
        } catch (SchemaKeywordException $exception) {
            throw ResponseValidationException::withError("{$shortHandler} has invalid schema: [ {$exception->getMessage()} ]");
        } catch (Exception $exception) {
            throw ResponseValidationException::withError($exception->getMessage());
        }

        if ($result instanceof ValidationResult && $result->isValid() === false) {
            $error = $result->getFirstError();
            $args = json_encode($error->keywordArgs());
            $dataPointer = implode('.', $error->dataPointer());

            throw ResponseValidationException::withError("{$shortHandler} json response field {$dataPointer} does not match the spec: [ {$error->keyword()}: {$args} ]", $result->getErrors());
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
}
