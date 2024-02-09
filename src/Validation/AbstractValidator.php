<?php

namespace Spectator\Validation;

use cebe\openapi\spec\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use stdClass;

abstract class AbstractValidator
{
    protected string $version;

    /**
     * Check if properties exist, and if so, prepare them based on version.
     *
     * @param  'read'|'write'|null  $mode
     */
    protected function prepareData(Schema $schema, ?string $mode = null): stdClass
    {
        return $this->prepareProperty($schema->getSerializableData(), $mode);
    }

    private function prepareProperty(stdClass $data, ?string $mode): stdClass
    {
        // Does this object contain an unresolved "$ref"? This occurs when `cebe\openapi\Reader`
        // encounters a cyclical reference. Skip it.
        if (data_get($data, '$ref')) {
            return $data;
        }

        $data = $this->migrateNullableTo31Style($data);

        if ($this->shouldHaveProperties($data)) {
            $data->properties ??= new stdClass();
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

    private function shouldHaveProperties(stdClass $data): bool
    {
        if (! isset($data->type)) {
            return false;
        }

        if (is_string($data->type)) {
            return $data->type === 'object';
        }

        if (is_array($data->type)) {
            return in_array('object', $data->type);
        }

        return false;
    }

    /**
     * Filters out readonly|writeonly properties.
     *
     * @param  string|null  $mode  Access mode 'read' or 'write'
     */
    private function filterProperties(stdClass $data, ?string $mode): stdClass
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
    private function migrateNullableTo31Style(stdClass $data): stdClass
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
