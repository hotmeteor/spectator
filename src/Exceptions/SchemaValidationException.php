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
                $error_location = str_replace(['0', '1'], '*', $sub_error['instanceLocation']);
                $error_location_map[$error_location] = $sub_error;
            }
        }

        // Create a map of errors using their keyword location. Keywords are stripped away
        // when map is created.
        $error_keyword_location_map = [];
        if (isset($error_formatted['errors'])) {
            foreach ($error_formatted['errors'] as $sub_error) {
                $keywords = ['/required', '/properties', '/type', '/format'];
                $keyword_location = str_replace($keywords, '', $sub_error['keywordLocation']);
                $keyword_location = str_replace(['0', '1'], '*', $keyword_location);
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
     * @param  array  $schema  JSON schema represented as an array
     * @param  string  $location  The current location/path within the JSON schema structure
     * @param  string  $key_current  The key at the current location
     * @param  array  $keys_required  The keys required at the current location
     * @param  int  $indent_level
     * @return array
     */
    public static function formatSchema($schema, $location, $key_current, $keys_required, $indent_level)
    {
        $keys = array_flip(array_keys($schema));
        $results = [];

        // first, check for polymorphic types...
        if (array_key_exists('allOf', $keys)) {
            $location .= '/allOf';
            $content = SchemaValidationException::expectedSchemaRowContent('allOf', '', $key_current, '');
            $results[$location] = SchemaValidationException::expectedSchemaRow($content, $indent_level);

            $indent_level = ++$indent_level;
            foreach ($schema['allOf'] as $index => $schema_object) {
                $results = array_merge($results, SchemaValidationException::formatSchema($schema_object, $location.'/'.$index, $key_current, [], $indent_level));
            }

            return $results;
        } elseif (array_key_exists('anyOf', $keys)) {
            $location .= '/anyOf';
            $content = SchemaValidationException::expectedSchemaRowContent('anyOf', '', $key_current, '');
            $results[$location] = SchemaValidationException::expectedSchemaRow($content, $indent_level);

            $indent_level = ++$indent_level;
            foreach ($schema['anyOf'] as $index => $schema_object) {
                $results = array_merge($results, SchemaValidationException::formatSchema($schema_object, $location.'/'.$index, $key_current, [], $indent_level));
            }

            return $results;
        } elseif (array_key_exists('oneOf', $keys)) {
            $location .= '/oneOf';
            $content = SchemaValidationException::expectedSchemaRowContent('oneOf', '', $key_current, '');
            $results[$location] = SchemaValidationException::expectedSchemaRow($content, $indent_level);

            $indent_level = ++$indent_level;
            foreach ($schema['oneOf'] as $index => $schema_object) {
                $results = array_merge($results, SchemaValidationException::formatSchema($schema_object, $location.'/'.$index, $key_current, [], $indent_level));
            }

            return $results;
        } elseif (isset($schema['type'])) { // then, check for all other types...
            // use "types array" to cover simple and mixed type cases
            $types = [];
            if (! is_array($schema['type'])) {
                $types = [$schema['type']];
            } else {
                $types = $schema['type'];
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
            if (in_array($key_current, $keys_required)) {
                $required = true;
            }

            // compute key modifiers
            $key_modifier = ($nullable) ?
                (($required) ? '?*' : '?') :
                (($required) ? '*' : '');

            // compute next location
            if ($key_current !== '') {
                $location .= '/'.$key_current;
            }

            // handle each type — could have multiple types per key
            foreach ($types as $type) {
                switch ($type) {
                    case 'object':
                        $additional_properties = true;
                        if (isset($schema['additionalProperties'])) {
                            if (is_bool($schema['additionalProperties'])) {
                                $additional_properties = $schema['additionalProperties'];
                            }
                        }

                        $content = SchemaValidationException::expectedSchemaRowContent(
                            'object',
                            ($additional_properties) ? '++' : '',
                            $key_current,
                            $key_modifier
                        );
                        $results[$location] = SchemaValidationException::expectedSchemaRow($content, $indent_level);

                        $indent_level = ++$indent_level;
                        foreach ($schema['properties'] as $key => $property) {
                            if (isset($schema['required'])) {
                                $results = array_merge($results, SchemaValidationException::formatSchema($property, $location, $key, $schema['required'], $indent_level));
                            } else {
                                $results = array_merge($results, SchemaValidationException::formatSchema($property, $location, $key, [], $indent_level));
                            }
                        }
                        break;
                    case 'array':
                        $content = SchemaValidationException::expectedSchemaRowContent('array', '', $key_current, $key_modifier);
                        $results[$location] = SchemaValidationException::expectedSchemaRow($content, $indent_level);

                        $results = array_merge($results, SchemaValidationException::formatSchema($schema['items'], $location.'/*', '', [], ++$indent_level));

                        break;
                    default:
                        $final_type = isset($schema['enum']) ? $type.' ['.join(', ', $schema['enum']).']' : $type;
                        $content = SchemaValidationException::expectedSchemaRowContent($final_type, '', $key_current, $key_modifier);
                        $results[$location] = SchemaValidationException::expectedSchemaRow($content, $indent_level);

                        break;
                }
            }

            return $results;
        }
    }

    public static function expectedSchemaRowContent($type, $type_modifier = '', $key = '', $key_modifier = '')
    {
        $key_final = $key.$key_modifier;
        $type_final = $type.$type_modifier;

        return (empty($key)) ? $type_final : $key_final.': '.$type_final;
    }

    public static function expectedSchemaRow($display, $indent_level = 0)
    {
        return str_repeat('    ', $indent_level).$display;
    }
}
