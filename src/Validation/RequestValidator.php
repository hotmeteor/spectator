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
        $required_parameters = array_filter($parameters, fn ($parameter) => $parameter->required === true);

        foreach ($required_parameters as $parameter) {
            // Verify presence, if required.
            if ($parameter->required === true) {
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

            // Validate schemas, if provided.
            if ($parameter->schema) {
                $validator = new Validator();
                $expected_parameter_schema = $parameter->schema->getSerializableData();
                $result = null;

                // Get parameter, then validate it.
                if ($parameter->in === 'path' && $route->hasParameter($parameter->name)) {
                    $actual_parameter = $route->parameters()[$parameter->name];
                } elseif ($parameter->in === 'query' && $this->request->query->has($parameter->name)) {
                    $actual_parameter = $this->request->query->get($parameter->name);
                } elseif ($parameter->in === 'header' && $this->request->headers->has($parameter->name)) {
                    $actual_parameter = $this->request->headers->get($parameter->name);
                } elseif ($parameter->in === 'cookie' && $this->request->cookies->has($parameter->name)) {
                    $actual_parameter = $this->request->cookies->get($parameter->name);
                }
                $result = $validator->validate($actual_parameter, $expected_parameter_schema);

                // If the result is not valid, then display failure reason.
                $expected_parameter = json_encode($expected_parameter_schema);
                if (!$result->isValid()) {
                    $message = '"Parameter [{$parameter->name}] did not match provided JSON schema.';
                    $message .= PHP_EOL.PHP_EOL.'  Keyword: '.$result->error()->keyword();
                    $message .= PHP_EOL.'  Expected: '.$expected_parameter;
                    $message .= PHP_EOL.'  Actual: '.$actual_parameter;
                    $message .= PHP_EOL.PHP_EOL.'  ---';

                    throw RequestValidationException::withError($message, $result->error());
                }
            }
        }
    }

    /**
     * @throws RequestValidationException
     */
    protected function validateBody(): void
    {
        $expected_body = $this->operation()->requestBody;
        $actual_body = $this->request->getContent();

        // If required, then body should be non-empty.
        if ($expected_body->required === true && empty($actual_body)) {
            throw new RequestValidationException('Request body required.');
            return;
        }

        // Content types should match.
        $content_type = $this->request->header('Content-Type');
        if (! array_key_exists($content_type, $expected_body->content)) {
            throw new RequestValidationException('Request did not match any specified media type for request body.');
        }

        // Capture schemas for validation.
        $expected_body_raw_schema = $expected_body->content[$content_type]->schema;
        $actual_body_schema = $actual_body;
        if ($expected_body_raw_schema->type === 'object' || $expected_body_raw_schema->type === 'array' || $expected_body_raw_schema->oneOf || $expected_body_raw_schema->anyOf) {
            if (! in_array($content_type, ['application/json', 'application/vnd.api+json'])) {
                throw new RequestValidationException("Unable to map [{$content_type}] to schema type [object].");
            }

            $actual_body_schema = json_decode($actual_body_schema);
        }
        $expected_body_schema = $this->prepareData($expected_body_raw_schema);

        // Run validation.
        $validator = new Validator();
        $result = $validator->validate($actual_body_schema, $expected_body_schema);

        // If the result is not valid, then display failure reason.
        $expected_body = json_encode($expected_body_schema);
        if (!$result->isValid()) {
            $message = 'Request body did not match provided JSON schema.';
            $message .= PHP_EOL.PHP_EOL.'  Keyword: '.$result->error()->keyword();
            $message .= PHP_EOL.'  Expected: '.$expected_body;
            $message .= PHP_EOL.'  Actual: '.$actual_body;
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
