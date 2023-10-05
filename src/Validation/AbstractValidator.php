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
     * @param  string|null  $mode  Access mode 'read' or 'write'
     * @return mixed
     */
    protected function prepareData(Schema $schema, string $mode = null)
    {
        $data = $schema->getSerializableData();

        if (! isset($data->properties)) {
            return $data;
        }

        $clone = clone $data;

        $clone = $this->filterProperties($clone, $mode);

        $v30 = Str::startsWith($this->version, '3.0');

        if ($v30) {
            $clone->properties = $this->wrapAttributesToArray($clone->properties);
        }

        return $clone;
    }

    /**
     * Filters out readonly|writeonly properties.
     *
     * @param  $data
     * @param  string|null  $type  Access mode 'read' or 'write'
     * @return mixed
     */
    protected function filterProperties(object $data, string $mode = null): object
    {
        if (data_get($data, '$ref')) {
            return $data;
        }

        switch ($mode) {
            case 'read':
                $filter_by = 'writeOnly';
                break;
            case 'write':
                $filter_by = 'readOnly';
                break;
            default:
                return $data;
        }

        if (isset($data->properties)) {
            /**
             * Create a new array of properties that need to be filtered out.
             */
            $filter_properties = array_keys(
                array_filter(
                    (array) $data->properties,
                    function ($property) use ($filter_by) {
                        return isset($property->$filter_by) && $property->$filter_by === true;
                    },
                )
            );

            /**
             * Filter out properties from schema's properties.
             */
            foreach ($filter_properties as $property) {
                unset($data->properties->$property);
            }

            /**
             * Filter out properties from schema's required properties array.
             * (Skip if nothing to filter out).
             */
            if (isset($data->required)) {
                $data->required = array_filter(
                    $data->required,
                    function ($property) use ($filter_properties) {
                        return ! in_array($property, $filter_properties);
                    },
                );
            }

            foreach ($data->properties as $key => $property) {
                $data->properties->$key = $this->parseProperty($property, $mode);
            }
        } else {
            $data = $this->parseProperty($data, $mode);
        }

        return $data;
    }

    private function parseProperty($property, ?string $mode)
    {
        if (isset($property->type)) {
            $type = $property->type;
        } elseif (isset($property->anyOf)) {
            $type = 'anyOf';
        } elseif (isset($property->allOf)) {
            $type = 'allOf';
        } elseif (isset($property->oneOf)) {
            $type = 'oneOf';
        } else {
            $type = null;
        }

        switch ($type) {
            case 'object':
                $property = $this->filterProperties($property, $mode);
                break;

            case 'array':
                $property->items = $this->filterProperties($property->items, $mode);
                break;

            case 'anyOf':
            case 'allOf':
            case 'oneOf':
                $property->$type = array_map(
                    function ($item) use ($mode) {
                        return $this->filterProperties($item, $mode);
                    },
                    $property->$type,
                );
                break;

            default:
                // Unknown type, skip
                break;
        }

        return $property;
    }

    /**
     * Return an associative array mapping "objects" to "properties" for the purposes of spec testing.
     * When this function finishes, you should have a structure with the following format:.
     *
     * [
     *     "Pet" => "{ resolved properties of a pet }"
     *     "Order" => "{ resolved properties of an order }"
     *     ...
     * ]
     *
     * @param  $properties
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
                if (isset($attributes->anyOf)) {
                    $attributes->anyOf[] = (object) ['type' => 'null'];
                } else {
                    $type = Arr::wrap($attributes->type ?? null);
                    $type[] = 'null';
                    $attributes->type = array_unique($type);
                }
                unset($attributes->nullable);
            }

            // anyOf -> recurse ...
            if (isset($attributes->anyOf)) {
                $attributes->anyOf = $this->wrapAttributesToArray($attributes->anyOf);
            }

            // Before we check recursive cases, make sure this object defines a "type".
            if (! isset($attributes->type)) {
                break;
            }

            // This object has a sub-object, recurse...
            if ($attributes->type === 'object' && isset($attributes->properties)) {
                $attributes->properties = $this->wrapAttributesToArray($attributes->properties);
            }

            // This object is an array of sub-objects, recurse...
            if (
                isset($attributes->items, $attributes->items->properties) && $attributes->type === 'array' && $attributes->items->type === 'object'
            ) {
                $attributes->items->properties = $this->wrapAttributesToArray($attributes->items->properties);
            }
            // This object is an array of anyOf, recurse...
            if (
                isset($attributes->items, $attributes->items->anyOf) && $attributes->type === 'array'
            ) {
                $attributes->items->anyOf = $this->wrapAttributesToArray($attributes->items->anyOf);
            }
        }

        return $properties;
    }
}
