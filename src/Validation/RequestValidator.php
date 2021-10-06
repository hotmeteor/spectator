<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\PathItem;
use Illuminate\Http\Request;
use Opis\JsonSchema\Validator;
use Spectator\Exceptions\RequestValidationException;

class RequestValidator extends AbstractValidator
{
    /**
     * @var Request
     */
    protected Request $request;

    /**
     * @var PathItem
     */
    protected PathItem $pathItem;

    /**
     * @var string
     */
    protected string $method;

    /**
     * RequestValidator constructor.
     *
     * @param  Request  $request
     * @param  PathItem  $pathItem
     * @param  string  $method
     * @param  string  $version
     */
    public function __construct(Request $request, PathItem $pathItem, string $method, string $version = '3.0')
    {
        $this->request = $request;
        $this->pathItem = $pathItem;
        $this->method = strtolower($method);
        $this->version = $version;
    }

    /**
     * @param  Request  $request
     * @param  PathItem  $pathItem
     * @param $method
     *
     * @throws RequestValidationException
     */
    public static function validate(Request $request, PathItem $pathItem, $method)
    {
        $instance = new self($request, $pathItem, $method);

        $instance->handle();
    }

    /**
     * @throws RequestValidationException
     */
    protected function handle()
    {
        $this->validateParameters();

        if ($this->operation()->requestBody !== null) {
            $this->validateBody();
        }
    }

    /**
     * @throws RequestValidationException
     */
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

    /**
     * @throws RequestValidationException
     */
    protected function validateBody(): void
    {
        $contentType = $this->request->header('Content-Type');
        $actual_request_body = $this->request->getContent();
        $body = $actual_request_body;
        $requestBody = $this->operation()->requestBody;

        if (empty($body)) {
            if ($requestBody->required === true) {
                throw new RequestValidationException('Request body required.');
            }

            return;
        }

        if (! array_key_exists($contentType, $requestBody->content)) {
            throw new RequestValidationException('Request did not match any specified media type for request body.');
        }

        $jsonSchema = $requestBody->content[$contentType]->schema;
        $validator = new Validator();

        if ($jsonSchema->type === 'object' || $jsonSchema->type === 'array' || $jsonSchema->oneOf || $jsonSchema->anyOf) {
            if (! in_array($contentType, ['application/json', 'application/vnd.api+json'])) {
                throw new RequestValidationException("Unable to map [{$contentType}] to schema type [object].");
            }

            $body = json_decode($body);
        }

        $expected_schema = $this->prepareData($jsonSchema);
        $expected_request_body = json_encode($expected_schema);

        $result = $validator->validate($body, $expected_schema);

        if (! $result->isValid()) {
            $message = 'Request body did not match provided JSON schema.';
            $message .= PHP_EOL.PHP_EOL.'  Keyword: '.$result->error()->keyword();
            $message .= PHP_EOL.'  Expected: '.$expected_request_body;
            $message .= PHP_EOL.'  Actual: '.$actual_request_body;
            $message .= PHP_EOL.PHP_EOL.'  ---';

            throw RequestValidationException::withError($message, $result->error());
        }
    }

    /**
     * @return Operation
     */
    protected function operation(): Operation
    {
        return $this->pathItem->{$this->method};
    }
}
