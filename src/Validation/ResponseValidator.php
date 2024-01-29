<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use Illuminate\Support\Str;
use Opis\JsonSchema\Validator;
use Spectator\Exceptions\ResponseValidationException;
use Spectator\Exceptions\SchemaValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * @throws SchemaValidationException
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
     * @throws SchemaValidationException
     */
    protected function parseResponse(Response $response)
    {
        $contentType = $this->contentType();

        // This is a bit hacky, but will allow resolving other JSON responses like application/problem+json
        // when returning standard JSON responses from frameworks (See hotmeteor/spectator#114)

        $specTypes = array_combine(
            array_keys($response->content),
            array_map(
                fn ($type) => $contentType === 'application/json' && Str::endsWith($type, '+json')
                    ? 'application/json'
                    : $type,
                array_keys($response->content)
            )
        );

        // Does the response match any of the specified media types?
        if (! in_array($contentType, $specTypes)) {
            $message = 'Response did not match any specified content type.';
            $message .= PHP_EOL.PHP_EOL.'  Expected: '.implode(', ', array_values($specTypes));
            $message .= PHP_EOL.'  Actual: '.$contentType;
            $message .= PHP_EOL.PHP_EOL.'  ---';

            throw new ResponseValidationException($message);
        }

        // Lookup the content type specified in the spec that match the application/json content type
        $contentType = array_flip($specTypes)[$contentType];

        $schema = $response->content[$contentType]->schema;

        $this->validateResponse(
            $schema,
            $this->body($contentType, $this->schemaType($schema))
        );
    }

    /**
     * @throws ResponseValidationException
     * @throws SchemaValidationException
     */
    protected function validateResponse(Schema $schema, $body)
    {
        $expectedSchema = $this->prepareData($schema, 'read');

        $validator = $this->validator();
        $result = $validator->validate($body, $expectedSchema);

        if ($result->isValid() === false) {
            $message = ResponseValidationException::validationErrorMessage($expectedSchema, $result->error());

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
     * @return mixed
     *
     * @throws ResponseValidationException
     */
    protected function body($contentType, $schemaType)
    {
        $body = $this->response instanceof StreamedResponse ? $this->streamedContent() : $this->response->getContent();

        if (in_array($schemaType, ['object', 'array', 'allOf', 'anyOf', 'oneOf'], true)) {
            if (in_array($contentType, ['application/json', 'application/vnd.api+json', 'application/problem+json'])) {
                return json_decode($body);
            } else {
                throw new ResponseValidationException("Unable to map [{$contentType}] to schema type [object].");
            }
        }

        return $body;
    }

    protected function streamedContent(): string
    {
        $content = '';

        ob_start(function (string $buffer) use (&$content): string {
            $content .= $buffer;

            return '';
        });

        $this->response->sendContent();

        ob_end_clean();

        return $content;
    }

    protected function validator(): Validator
    {
        return new Validator();
    }
}
