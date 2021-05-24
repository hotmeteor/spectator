<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Operation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait SchemaValidator
{
    protected function prepareData($data)
    {
        if (! isset($data->properties)) {
            return $data;
        }

        $clone = clone $data;

        $v30 = Str::startsWith($this->version, '3.0');

        if ($v30) {
            $clone->properties = $this->wrapAttributesToArray($clone->properties);
        }

        return $clone;
    }

    protected function wrapAttributesToArray($properties)
    {
        foreach ($properties as $key => $attributes) {
            if (isset($attributes->nullable)) {
                $type = Arr::wrap($attributes->type);
                $type[] = 'null';
                $attributes->type = array_unique($type);
                unset($attributes->nullable);
            }

            if ($attributes->type === 'object' && isset($attributes->properties)) {
                $attributes->properties = $this->wrapAttributesToArray($attributes->properties);
            }

            if (
                $attributes->type === 'array'
                && isset($attributes->items)
                && isset($attributes->items->properties)
                && $attributes->items->type === 'object'
            ) {
                $attributes->items->properties = $this->wrapAttributesToArray($attributes->items->properties);
            }
        }

        return $properties;
    }
}