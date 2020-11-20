<?php

namespace Spectator\Validation;

use Opis\JsonSchema\Validator;
use cebe\openapi\spec\Operation;
use Opis\JsonSchema\Exception\SchemaKeywordException;
use Spectator\Exceptions\ResponseValidationException;

class ResponseValidator
{
    /** @var string request uri */
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

    protected function handle()
    {
        $contentType = $this->response->headers->get('Content-Type');
        $body = $this->response->getContent();
        $responses = $this->operation->responses;

        $shortHandler = class_basename($this->operation->operationId) ?: $this->uri;

        // Get matching response object based on status code.
        if ($responses[$this->response->getStatusCode()] !== null) {
            $responseObject = $responses[$this->response->getStatusCode()];
        } elseif ($responses['default'] !== null) {
            $responseObject = $responses['default'];
        } else {
            throw new ResponseValidationException("No response object matching returned status code [{$this->response->getStatusCode()}].");
        }

        if ($responseObject->content) {
            if (!array_key_exists($contentType, $responseObject->content)) {
                throw new ResponseValidationException('Response did not match any specified media type.');
            }

            $schema = $responseObject->content[$contentType]->schema;

            if ($schema->type === 'object' || $schema->type === 'array') {
                if (in_array($contentType, ['application/json', 'application/vnd.api+json'])) {
                    $body = json_decode($body);
                } else {
                    throw new ResponseValidationException("Unable to map [{$contentType}] to schema type [object].");
                }
            }

            $validator = $this->validator();
            $result = null;

            try {
                $result = $validator->dataValidation($body, $schema->getSerializableData(), -1);
            } catch (SchemaKeywordException $exception) {
                throw ResponseValidationException::withError("{$shortHandler} has invalid schema: [ {$exception->getMessage()} ]");
            } catch (\Exception $exception) {
                throw ResponseValidationException::withError($exception->getMessage());
            }

            if (optional($result)->isValid() === false) {
                $error = $result->getFirstError();
                $args = json_encode($error->keywordArgs());
                $dataPointer = implode('.', $error->dataPointer());

                throw ResponseValidationException::withError("{$shortHandler} json response field {$dataPointer} does not match the spec: [ {$error->keyword()}: {$args} ]", $result->getErrors());
            }
        }
    }

    protected function validator(): Validator
    {
        $validator = new Validator();

        return $validator;
    }
}
