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
}
