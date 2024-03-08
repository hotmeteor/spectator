<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use Illuminate\Support\Str;
use Opis\JsonSchema\Validator;
use Spectator\Exceptions\ResponseValidationException;
use Spectator\Exceptions\SchemaValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseValidator extends AbstractValidator
{
    public function __construct(
        protected HttpResponse $response,
        protected Operation $operation,
        string $version = '3.0'
    ) {
        $this->version = $version;
    }

    public static function validate(HttpResponse $response, Operation $operation, string $version = '3.0'): void
    {
        $instance = new self($response, $operation, $version);

        $instance->handle();
    }

    /**
     * @throws ResponseValidationException
     * @throws SchemaValidationException
     */
    protected function handle(): void
    {
        $responseObject = $this->response();

        if ($responseObject->content) {
            $this->parseResponse($responseObject);
        } elseif ($this->responseContent() !== '') {
            throw new ResponseValidationException('Response body is expected to be empty.');
        }
    }

    /**
     * @throws ResponseValidationException
     * @throws SchemaValidationException
     */
    protected function parseResponse(Response $response): void
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
    protected function validateResponse(Schema $schema, mixed $body): void
    {
        $expectedSchema = $this->prepareData($schema, 'read');

        $validator = new Validator();
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

    protected function contentType(): ?string
    {
        return $this->response->headers->get('Content-Type') !== null
            ? Str::before($this->response->headers->get('Content-Type'), ';')
            : null;
    }

    protected function schemaType(Schema $schema): ?string
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

    protected function body(?string $contentType, ?string $schemaType): mixed
    {
        $body = $this->responseContent();

        if (in_array($schemaType, ['object', 'array', 'allOf', 'anyOf', 'oneOf'], true)) {
            if (in_array($contentType, ['application/json', 'application/vnd.api+json', 'application/problem+json'], true)) {
                return json_decode($body);
            } else {
                throw new ResponseValidationException("Unable to map [{$contentType}] to schema type [object].");
            }
        }

        return $body;
    }

    protected function responseContent(): string
    {
        return $this->response instanceof StreamedResponse ? $this->streamedContent() : $this->response->getContent();
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
}
