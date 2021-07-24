<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

abstract class AbstractValidator
{
    protected string $version;

    /**
     * Check if properties exist, and if so, prepare them based on version.
     *
     * @param  Schema  $schema
     * @return mixed
     */
    protected function prepareData(Schema $schema)
    {
        $data = $schema->getSerializableData();

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

    /**
     * Wrap attributes in array and resolve nullable properties.
     *
     * @param $properties
     * @return mixed
     */
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
