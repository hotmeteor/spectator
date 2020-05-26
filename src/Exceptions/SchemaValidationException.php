<?php

namespace Spectator\Exceptions;

use Exception;

abstract class SchemaValidationException extends Exception
{
    private $errors = [];

    public static function withSchemaErrors(string $message, array $errors = [])
    {
        $instance = new static($message);
        $instance->errors = $errors;

        return $instance;
    }

    public function getRawErrors()
    {
        return $this->errors;
    }

    public function getErrors()
    {
        return array_map(function ($error) {
            switch ($error['constraint']) {
                case 'enum':
                    $enum = implode(', ', $error['enum']);

                    return "The [{$error['property']}] property must be one of [{$enum}].";
                    break;
                case 'required':
                    return "The [{$error['property']}] property is missing. It must be included.";
                    break;
                case 'additionalProp':
                    return str_replace('definition', "definition for [{$error['property']}]", $error['message']).'.';
                    break;
                case 'type':
                    return $error['message']." for property [${error['property']}].";
                default:
                    return $error['message'];
            }
        }, $this->errors);
    }
}
