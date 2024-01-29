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
     * @param  string|null  $mode  Access mode 'read' or 'write'
     * @return mixed
     */
    protected function prepareData(Schema $schema, ?string $mode = null): object
    {
        return $this->prepareProperty($schema->getSerializableData(), $mode);
    }

    private function prepareProperty(object $data, ?string $mode): object
    {
        // Does this object contain an unresolved "$ref"? This occurs when `cebe\openapi\Reader`
        // encounters a cyclical reference. Skip it.
        if (data_get($data, '$ref')) {
            return $data;
        }

        $data = $this->migrateNullableTo31Style($data);

        if (isset($data->type) && $data->type === 'object') {
            $data->properties ??= new \stdClass();
        }

        match (true) {
            isset($data->properties) => (function () use ($data, $mode) {
                $data = $this->filterProperties($data, $mode);
                foreach ($data->properties as $key => $property) {
                    $data->properties->$key = $this->prepareProperty($property, $mode);
                }
            })(),
            isset($data->items) => $data->items = $this->prepareProperty($data->items, $mode),
            isset($data->anyOf) => $data->anyOf = array_map(
                fn ($item) => $this->prepareProperty($item, $mode),
                $data->anyOf,
            ),
            isset($data->allOf) => $data->allOf = array_map(
                fn ($item) => $this->prepareProperty($item, $mode),
                $data->allOf,
            ),
            isset($data->oneOf) => $data->oneOf = array_map(
                fn ($item) => $this->prepareProperty($item, $mode),
                $data->oneOf,
            ),
            default => null
        };

        return $data;
    }

    /**
     * Filters out readonly|writeonly properties.
     *
     * @param  string|null  $mode  Access mode 'read' or 'write'
     */
    private function filterProperties(object $data, ?string $mode): object
    {
        $filterBy = match ($mode) {
            'read' => 'writeOnly',
            'write' => 'readOnly',
            default => null,
        };

        if ($filterBy === null) {
            return $data;
        }

        // Create a new array of properties that need to be filtered out.
        $filterProperties = array_keys(
            array_filter(
                (array) $data->properties,
                function ($property) use ($filterBy) {
                    return isset($property->$filterBy) && $property->$filterBy === true;
                },
            )
        );

        //Filter out properties from schema's properties.
        foreach ($filterProperties as $property) {
            unset($data->properties->$property);
        }

        // Filter out properties from schema's required properties array.
        // (Skip if nothing to filter out).
        if (isset($data->required)) {
            $data->required = array_filter(
                $data->required,
                fn ($property) => ! in_array($property, $filterProperties),
            );
        }

        return $data;
    }

    /**
     * Migrate Openapi 3.0 nullable declaration to Openapi 3.1 style.
     */
    private function migrateNullableTo31Style(object $data): object
    {
        if (! Str::startsWith($this->version, '3.0')) {
            return $data;
        }

        // Does this object define "nullable"? If so, unset "nullable" and include "null"
        // in array of possible types (e.g. "type" => [..., "null"]).
        if (isset($data->nullable)) {
            if (isset($data->anyOf)) {
                $data->anyOf[] = (object) ['type' => 'null'];
            } else {
                $type = Arr::wrap($data->type ?? null);
                $type[] = 'null';
                $data->type = array_unique($type);
            }
            unset($data->nullable);
        }

        return $data;
    }
}
