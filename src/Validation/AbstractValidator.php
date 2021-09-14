<?php

namespace Spectator\Validation;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

abstract class AbstractValidator
{
    protected $version;

    /**
     * Check if properties exist, and if so, prepare them based on version.
     *
     * @param $data
     * @return mixed
     */
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

    /**
     * Returns an associate array mapping "objects" to "properties" for the purposes of spec testing.
     * All nullable properties are resolved. When this function finishes, you should have a
     * structure with the following format:.
     *
     * [
     *     "Pet" => "{ resolved properties of a pet }"
     *     "Order" => "{ resolved properties of an order }"
     *     ...
     * ]
     *
     * @param $properties
     * @return mixed
     */
    protected function wrapAttributesToArray($properties)
    {
        foreach ($properties as $key => $attributes) {
            // Does this object contain an unresolved "$ref"? This occurs when `cebe\openapi\Reader`
            // encounters a cyclical reference. Skip it.
            if (data_get($attributes, '$ref')) {
                break;
            }

            // Does this object define "nullable"? If so, unset "nullable" and include "null"
            // in array of possible types (e.g. "type" => [..., "null"]).
            if (isset($attributes->nullable)) {
                $type = Arr::wrap($attributes->type);
                $type[] = 'null';
                $attributes->type = array_unique($type);
                unset($attributes->nullable);
            }

            // This object has a sub-object, recurse...
            if ($attributes->type === 'object' && isset($attributes->properties)) {
                $attributes->properties = $this->wrapAttributesToArray($attributes->properties);
            }

            // This object is an array of sub-objects, recurse...
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
