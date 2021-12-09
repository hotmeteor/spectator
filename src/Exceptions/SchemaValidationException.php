<?php

namespace Spectator\Exceptions;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Symfony\Component\Console\Exception\ExceptionInterface;

abstract class SchemaValidationException extends \Exception implements ExceptionInterface
{
    /**
     * @var
     */
    protected array $errors = [];

    /**
     * @param  string  $message
     * @param  ValidationError  $error
     * @return static
     */
    public static function withError(string $message, ValidationError $error)
    {
        $instance = new static($message);

        $formatter = new ErrorFormatter();

        $instance->errors = $formatter->formatFlat($error);

        return $instance;
    }

    /**
     * @param  ValidationError  $error
     */
    protected function setErrors(ValidationError $error)
    {
        $formatter = new ErrorFormatter();

        $this->errors = $formatter->formatFlat($error);
    }

    /**
     * Return the exception errors.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Check if the exception has errors.
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    /**
     * Helper functions for displaying a validation error.
     */
    public static function validationErrorMessage($schema, $validation_error)
    {
        $error_formatted = SchemaValidationException::formatValidationError($validation_error, false);

        // Create a map of errors using their location.
        $error_location_map = [];
        if (isset($error_formatted['errors'])) {
            foreach ($error_formatted['errors'] as $sub_error) {
                $error_location_map[$sub_error['instanceLocation']] = $sub_error;
            }
        }

        // Create a map of errors using their keyword location. Keywords are stripped away
        // when map is created.
        $error_keyword_location_map = [];
        if (isset($error_formatted['errors'])) {
            foreach ($error_formatted['errors'] as $sub_error) {
                $keywords = ['/required', '/properties', '/type', '/format'];
                $keyword_location = str_replace($keywords, '', $sub_error['keywordLocation']);
                $error_keyword_location_map[$keyword_location] = $sub_error;
            }
        }

        // Convert expected schema into an array for processing.
        $schema = json_decode(json_encode($schema), true);

        // Create a structured map of strings representing the schema.
        $schema_formatted = SchemaValidationException::formatSchema($schema, '#', '', [], 0);

        // Display each item in the schema map. If the item is also
        // the location of a matching error, then display it too.
        $strings = [];
        if (! is_null($schema_formatted)) {
            foreach ($schema_formatted as $key => $schema_item) {
                if (isset($error_location_map[$key])) {
                    $strings[] = $schema_item.' <== '.$error_location_map[$key]['error'];
                } elseif (isset($error_keyword_location_map[$key])) {
                    $strings[] = $schema_item.' <== '.$error_keyword_location_map[$key]['error'];
                } else {
                    $strings[] = $schema_item;
                }
            }
        }

        // Flat display of errors
        $error_flat = join("\n", SchemaValidationException::formatValidationError($validation_error, true));

        return "---\n\n".$error_flat."\n\n".join("\n", $strings)."\n\n  ---";
        //return "---\n\n".join("\n", $strings)."\n\n  ---";
    }

    public static function formatValidationError($validation_error, $flat = false)
    {
        $formatter = new ErrorFormatter();

        return ($flat) ? $formatter->formatFlat($validation_error) :
            $formatter->formatOutput($validation_error, 'basic');
    }

    /**
     *
     * Recursive function that, given a schema, creates a map of underlying schema items intended display.
     * Each item is keyed/identified using the schema's "location" — with a format similar
     * to Opis\JsonSchema\Errors. The value for each item is a display string representing a schema item,
     * its type, and other relevant information, when provided.
     *
     * Here's an example map:
     *
     * [
     *     "#" => "object++"
     *     "#/status" => "    status*: string"
     *     "#/message" => "    message*: string"
     * ]
     *
     * And when output, it might be displayed as follows:
     *
     * object++
     *     status*: string
     *     message*: string
     *
     * Because the items are keyed by a "location" similar to Opis\JsonSchema\Errors. We can easily
     * overlay errors at the call site:
     *
     * object++ <== The properties must match schema: message
     *     status*: string
     *     message*: string <== The data (integer) must match the type: string
     *
     * @param  array  $schema  JSON schema represented as an array
     * @param  string  $location_current  The current location within the JSON schema structure
     * @param  string  $key_current  The key at the current location, if one is present.
     * @param  array  $keys_required  The keys required at the current location, if provided.
     * @param  int  $indent_level  Represents how much newly added values should be indented.
     * @return array
     *
     */
    public static function formatSchema($schema, $location_current, $key_current, $keys_required, $indent_level)
    {
        $keys_at_location = array_flip(array_keys($schema));
        $schema_map = [];

        // is this a polymorphic schema?
        $polymorphic_keys = array_filter($keys_at_location, function ($key) {
            return $key == 'allOf' || $key == 'anyOf' || $key == 'oneOf';
        }, ARRAY_FILTER_USE_KEY);
        $polymorphic_keys = array_flip($polymorphic_keys);

        if (! empty($polymorphic_keys)) { // first, check for a polymorphic schema...
            $polymorphic_key = $polymorphic_keys[0];

            // create entry for polymorphic schema
            $location_current .= '/'.$polymorphic_key;
            $display_string = SchemaValidationException::schemaItemDisplayString($polymorphic_key, '', $key_current, '');
            $schema_map[$location_current] = SchemaValidationException::indentedDisplayString($display_string, $indent_level);

            $indent_level = ++$indent_level;
            foreach ($schema[$polymorphic_key] as $index => $next_schema) {
                $schema_map = array_merge($schema_map, SchemaValidationException::formatSchema($next_schema, $location_current.'/'.$index, $key_current, [], $indent_level));
            }

            return $schema_map;
        } elseif (isset($schema['type'])) { // otherwise, check for explicit schema type...
            // convert "type" to an array (to support single/multiple types)
            $types = [];
            if (! is_array($schema['type'])) {
                $types = [$schema['type']];
            } else {
                $types = $schema['type'];
            }

            // is "null" an included type? if so, make note of it and remove it from the types array
            $nullable = false;
            $null_index = array_search('null', $types);
            if ($null_index) {
                $nullable = true;
                unset($types[$null_index]);
            }

            // is this item required?
            $required = false;
            if (in_array($key_current, $keys_required)) {
                $required = true;
            }

            // compute key modifiers
            $key_modifier = ($nullable) ?
                (($required) ? '?*' : '?') :
                (($required) ? '*' : '');

            // compute next location
            if ($key_current !== '') {
                $location_current .= '/'.$key_current;
            }

            // handle each schema type
            foreach ($types as $type) {
                switch ($type) {
                    case 'object':
                        // does this object support additional properties?
                        $additional_properties = true;
                        if (isset($schema['additionalProperties'])) {
                            if (is_bool($schema['additionalProperties'])) {
                                $additional_properties = $schema['additionalProperties'];
                            }
                        }

                        // create entry for object schema
                        $display_string = SchemaValidationException::schemaItemDisplayString(
                            'object',
                            ($additional_properties) ? '++' : '',
                            $key_current,
                            $key_modifier
                        );
                        $schema_map[$location_current] = SchemaValidationException::indentedDisplayString($display_string, $indent_level);

                        // create entires for all object properties
                        $indent_level = ++$indent_level;
                        foreach ($schema['properties'] as $key => $next_schema) {
                            if (isset($schema['required'])) {
                                $schema_map = array_merge($schema_map, SchemaValidationException::formatSchema($next_schema, $location_current, $key, $schema['required'], $indent_level));
                            } else {
                                $schema_map = array_merge($schema_map, SchemaValidationException::formatSchema($next_schema, $location_current, $key, [], $indent_level));
                            }
                        }
                        break;
                    case 'array':
                        // create entry for array schema
                        $display_string = SchemaValidationException::schemaItemDisplayString('array', '', $key_current, $key_modifier);
                        $schema_map[$location_current] = SchemaValidationException::indentedDisplayString($display_string, $indent_level);

                        // create entry for array's items
                        $next_schema = $schema['items'];
                        $schema_map = array_merge($schema_map, SchemaValidationException::formatSchema($next_schema, $location_current.'/items', '', [], ++$indent_level));

                        break;
                    default:
                        // create entry for basic schema
                        $final_type = isset($schema['enum']) ? $type.' ['.join(', ', $schema['enum']).']' : $type;
                        $display_string = SchemaValidationException::schemaItemDisplayString($final_type, '', $key_current, $key_modifier);
                        $schema_map[$location_current] = SchemaValidationException::indentedDisplayString($display_string, $indent_level);

                        break;
                }
            }

            return $schema_map;
        }
    }

    public static function schemaItemDisplayString($type, $type_modifier = '', $key = '', $key_modifier = '')
    {
        $key_final = $key.$key_modifier;
        $type_final = $type.$type_modifier;

        return (empty($key)) ? $type_final : $key_final.': '.$type_final;
    }

    public static function indentedDisplayString($display_string, $indent_level = 0)
    {
        return str_repeat('    ', $indent_level).$display_string;
    }
}
