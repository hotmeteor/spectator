<?php

namespace Spectator\Exceptions;

use Exception;

abstract class SchemaValidationException extends Exception
{
    private $errors = [];

    public static function withError($message, $errors)
    {
        $instance = new static($message);
        $instance->errors = (array) $errors;

        return $instance;
    }

    public function getRawErrors()
    {
        return $this->errors;
    }

    public function getErrors()
    {
        return json_encode($this->errors, JSON_PRETTY_PRINT).PHP_EOL;
    }
}
