<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use Illuminate\Support\Collection;
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
        $specTypes = array_keys($response->content);

        // Does the response match any of the specified media types?
        $matchingType = $this->findMatchingType($contentType, $specTypes);
        if ($matchingType === null) {
            $message = 'Response did not match any specified content type.';
            $message .= PHP_EOL.PHP_EOL.'  Expected: '.implode(', ', $specTypes);
            $message .= PHP_EOL.'  Actual: '.$contentType;
            $message .= PHP_EOL.PHP_EOL.'  ---';

            throw new ResponseValidationException($message);
        }

        $schema = $response->content[$matchingType]->schema;

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

        $validator = new Validator;
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
            if ($this->isJsonContentType($contentType)) {
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
        assert($this->response instanceof StreamedResponse);

        $content = '';

        ob_start(function (string $buffer) use (&$content): string {
            $content .= $buffer;

            return '';
        });

        ($this->response->getCallback() ?? fn () => null)();

        ob_end_clean();

        return $content;
    }

    /**
     * @param  array<int, string>  $specTypes
     */
    private function findMatchingType(?string $contentType, array $specTypes): ?string
    {
        if ($contentType === null) {
            return null;
        }
        if ($this->isJsonContentType($contentType)) {
            $contentType = 'application/json';
        }

        // This is a bit hacky, but will allow resolving other JSON responses like application/problem+json
        // when returning standard JSON responses from frameworks (See hotmeteor/spectator#114)

        $normalizedSpecTypes = Collection::make($specTypes)->mapWithKeys(fn (string $type) => [
            $type => $this->isJsonContentType($type) ? 'application/json' : $type,
        ])->all();

        $matchingTypes = [$contentType, Str::before($contentType, '/').'/*', '*/*'];
        foreach ($matchingTypes as $matchingType) {
            if (in_array($matchingType, $normalizedSpecTypes, true)) {
                return array_flip($normalizedSpecTypes)[$matchingType];
            }
        }

        return null;
    }

    private function isJsonContentType(string $contentType): bool
    {
        return $contentType === 'application/json' || Str::endsWith($contentType, '+json');
    }
}
