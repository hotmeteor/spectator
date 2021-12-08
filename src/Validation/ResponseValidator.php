<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Schema;
use Opis\JsonSchema\Errors\ErrorFormatter;
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

        // Does the response match any of the specified media types?
        if (! array_key_exists($contentType, $response->content)) {
            $message = 'Response did not match any specified content type.';
            $message .= PHP_EOL.PHP_EOL.'  Expected: '.$contentType;
            $message .= PHP_EOL.'  Actual: DNE';
            $message .= PHP_EOL.PHP_EOL.'  ---';
            throw new ResponseValidationException($message);
        }

        $schema = $response->content[$contentType]->schema;

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
        $expected_schema = $this->prepareData($schema);

        $validator = $this->validator();
        $result = $validator->validate($body, $expected_schema);

        if ($result instanceof ValidationResult && $result->isValid() === false) {
            $error = $result->error();
            $formatter = new ErrorFormatter();
            $spec_errors = join("\n", $formatter->formatFlat($error));
            $spec_errors .= "\n\n".json_encode($formatter->formatOutput($error, 'basic'));

            $expected_schema_json = json_decode(json_encode($expected_schema), true);
            $expected_schema_pretty = $this->pretty_print($expected_schema_json, '', [], 0);

            $message = "---\n\n".$spec_errors."\n\n".$expected_schema_pretty."\n  ---";

            throw ResponseValidationException::withError($message, $error);
        }
    }

    protected function pretty_print_row($type, $type_modifier = '', $key = '', $key_modifier = '', $indent_level = 0)
    {
        $key_final = (empty($key_modifier)) ? $key : $key.$key_modifier;
        $type_final = (empty($type_modifier)) ? $type : $type.$type_modifier;

        if (empty($key)) {
            return str_repeat('    ', $indent_level).$type_final."\n";
        } else {
            return str_repeat('    ', $indent_level).$key_final.': '.$type_final."\n";
        }
    }

    protected function pretty_print($json, $key, $required_keys, $indent_level)
    {
        $keys = array_keys($json);
        $results = '';

        // first, check for polymorphic types...
        if (array_key_exists('allOf', $keys)) {
            $results .= $this->pretty_print_row('allOf', '', $key, '', $indent_level);
            foreach ($json['allOf'] as $schema_object) {
                $results .= $this->pretty_print($schema_object, $key, [], ++$indent_level);
            }

            return $results;
        } elseif (array_key_exists('anyOf', $keys)) {
            $results .= $this->pretty_print_row('anyOf', '', $key, '', $indent_level);
            foreach ($json['anyOf'] as $schema_object) {
                $results .= $this->pretty_print($schema_object, $key, [], ++$indent_level);
            }

            return $results;
        } elseif (array_key_exists('oneOf', $keys)) {
            $results .= $this->pretty_print_row('oneOf', '', $key, '', $indent_level);
            foreach ($json['oneOf'] as $schema_object) {
                $results .= $this->pretty_print($schema_object, $key, [], ++$indent_level);
            }

            return $results;
        } elseif (isset($json['type'])) { // then, check for all other types...
            // use "types array" to cover simple and mixed type cases
            $types = [];
            if (! is_array($json['type'])) {
                $types = [$json['type']];
            } else {
                $types = $json['type'];
            }

            // is "null" type used?
            $nullable = false;
            $null_index = array_search('null', $types);
            if ($null_index) {
                $nullable = true;
                unset($types[$null_index]);
            }

            // is this required?
            $required = false;
            if (in_array($key, $required_keys)) {
                $required = true;
            }

            // compute key modifiers
            $key_modifier = ($nullable) ?
                (($required) ? '?*' : '?') :
                (($required) ? '*' : '');

            // handle each type — could have multiple types per key
            foreach ($types as $type) {
                switch ($type) {
                    case 'object':
                        $additional_properties = false;
                        if (isset($json['additionalProperties'])) {
                            if (is_bool($json['additionalProperties'])) {
                                $additional_properties = $json['additionalProperties'];
                            }
                        }

                        $results .= $this->pretty_print_row(
                            'object',
                            ($additional_properties) ? ' ++' : '',
                            $key,
                            $key_modifier,
                            $indent_level
                        );

                        $indent_level = ++$indent_level;
                        foreach ($json['properties'] as $key => $property) {
                            if (isset($json['required'])) {
                                $results .= $this->pretty_print($property, $key, $json['required'], $indent_level);
                            } else {
                                $results .= $this->pretty_print($property, $key, [], $indent_level);
                            }
                        }
                        break;
                    case 'array':
                        $results .= $this->pretty_print_row('array', '', $key, $key_modifier, $indent_level);
                        $results .= $this->pretty_print($json['items'], '', [], ++$indent_level);
                        break;
                    default:
                        $results .= $this->pretty_print_row($type, '', $key, $key_modifier, $indent_level);
                        break;
                }
            }

            return $results;
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

        if (in_array($schemaType, ['object', 'array', 'allOf', 'anyOf', 'oneOf'], true)) {
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
