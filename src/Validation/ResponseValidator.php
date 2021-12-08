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
            $message = $this->validation_error_message($expected_schema, $error);
            throw ResponseValidationException::withError($message, $error);
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

    /**
     * Helper functions for displaying a validation error.
     */

    protected function validation_error_message($schema, $validation_error)
    {
        $error_formatted = $this->format_validation_error($validation_error, false);

        // Create a map of errors using their location.
        $error_map = [];
        foreach ($error_formatted['errors'] as $sub_error) {
            $error_map[$sub_error['instanceLocation']] = $sub_error;
        }

        // Convert expected schema into an array for processing.
        $schema = json_decode(json_encode($schema), true);

        // Create a structured map of strings representing the schema.
        $schema_formatted = $this->format_schema($schema, "#", "", [], 0);

        // Display each item in the schema map. If the item is also
        // the location of a matching error, then display it too.
        $strings = [];
        foreach ($schema_formatted as $key => $schema_item) {
            if (isset($error_map[$key])) {
                $strings[] = $schema_item.' <== '.$error_map[$key]['error'];
            } else {
                $strings[] = $schema_item;
            }
        }

        // Flat display of errors
        $error_flat = join("\n", $this->format_validation_error($validation_error, true));

        // return "---\n\n".$error_flat."\n\n".join("\n", $strings)."\n\n  ---";
        return "---\n\n".join("\n", $strings)."\n\n  ---";
    }

    protected function format_validation_error($validation_error, $flat = false)
    {
        $formatter = new ErrorFormatter();

        return ($flat) ? $formatter->formatFlat($validation_error) :
            $formatter->formatOutput($validation_error, "basic");
    }

    /**
     * @param array $schema JSON schema represented as an array
     * @param string $location The current location/path within the JSON schema structure
     * @param string $key_current The key at the current location
     * @param array $keys_required The keys required at the current location
     * @param int $indent_level
     * @return array
     */
    protected function format_schema($schema, $location, $key_current, $keys_required, $indent_level)
    {
        $keys = array_keys($schema);
        $results = [];

        // first, check for polymorphic types...
        if (array_key_exists('allOf', $keys)) {
            $location .= '/allOf';
            $content = $this->expected_schema_row_content('allOf', "", $key_current, "");
            $results[$location] = $this->expected_schema_row($content, $indent_level);

            foreach ($schema['allOf'] as $schema_object) {
                $results = array_merge($results, $this->format_schema($schema_object, $location, $key_current, [], ++$indent_level));
            }

            return $results;
        } elseif (array_key_exists('anyOf', $keys)) {
            $location .= '/anyOf';
            $content = $this->expected_schema_row_content('anyOf', "", $key_current, "");
            $results[$location] = $this->expected_schema_row($content, $indent_level);

            foreach ($schema['anyOf'] as $schema_object) {
                $results = array_merge($results, $this->format_schema($schema_object, $location, $key_current, [], ++$indent_level));
            }

            return $results;
        } elseif (array_key_exists('oneOf', $keys)) {
            $location .= '/oneOf';
            $content = $this->expected_schema_row_content('oneOf', "", $key_current, "");
            $results[$location] = $this->expected_schema_row($content, $indent_level);

            foreach ($schema['oneOf'] as $schema_object) {
                $results = array_merge($results, $this->format_schema($schema_object, $location, $key_current, [], ++$indent_level));
            }

            return $results;
        } elseif (isset($schema['type'])) { // then, check for all other types...
            // use "types array" to cover simple and mixed type cases
            $types = [];
            if (!is_array($schema['type'])) {
                $types = [$schema['type']];
            } else {
                $types = $schema['type'];
            }

            // is "null" type used?
            $nullable = false;
            $null_index = array_search("null", $types);
            if ($null_index) {
                $nullable = true;
                unset($types[$null_index]);
            }

            // is this required?
            $required = false;
            if (in_array($key_current, $keys_required)) {
                $required = true;
            }

            // compute key modifiers
            $key_modifier = ($nullable) ?
                (($required) ? "?*" : "?") :
                (($required) ? "*" : "");

            // compute next location
            if ($key_current !== "") {
                $location .= '/'.$key_current;
            }

            // handle each type — could have multiple types per key
            foreach ($types as $type) {
                switch ($type) {
                    case "object":
                        $additional_properties = true;
                        if (isset($schema['additionalProperties'])) {
                            if (is_bool($schema['additionalProperties'])) {
                                $additional_properties = $schema['additionalProperties'];
                            }
                        }

                        $content = $this->expected_schema_row_content(
                            'object',
                            ($additional_properties) ? "++" : "",
                            $key_current,
                            $key_modifier
                        );
                        $results[$location] = $this->expected_schema_row($content, $indent_level);

                        $indent_level = ++$indent_level;
                        foreach ($schema['properties'] as $key => $property) {
                            if (isset($schema['required'])) {
                                $results = array_merge($results, $this->format_schema($property, $location, $key, $schema['required'], $indent_level));
                            } else {
                                $results = array_merge($results, $this->format_schema($property, $location, $key, [], $indent_level));
                            }
                        }
                        break;
                    case "array":
                        $content = $this->expected_schema_row_content('array', "", $key_current, $key_modifier);
                        $results[$location] = $this->expected_schema_row($content, $indent_level);

                        $results = array_merge($results, $this->format_schema($schema['items'], $location.'/1', "", [], ++$indent_level));

                        break;
                    default:
                        $content = $this->expected_schema_row_content($type, "", $key_current, $key_modifier);
                        $results[$location] = $this->expected_schema_row($content, $indent_level);

                        break;
                }
            }

            return $results;
        }
    }

    protected function expected_schema_row_content($type, $type_modifier = "", $key = "", $key_modifier = "")
    {
        $key_final = $key.$key_modifier;
        $type_final = $type.$type_modifier;

        return (empty($key)) ? $type_final : $key_final.": ".$type_final;
    }

    protected function expected_schema_row($display, $indent_level = 0)
    {
        return str_repeat("    ", $indent_level).$display;
    }
}
