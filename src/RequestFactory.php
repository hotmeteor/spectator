<?php

namespace Spectator;

use cebe\openapi\Reader;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use cebe\openapi\spec\OpenApi;
use Illuminate\Support\Traits\Macroable;
use Spectator\Exceptions\MissingSpecException;

class RequestFactory
{
    use Macroable;

    protected $specName = null;

    public function using($name)
    {
        $this->specName = $name;
    }

    public function getSpec(): ?string
    {
        return $this->specName;
    }

    public function reset()
    {
        $this->specName = null;
    }

    public function resolve(): OpenApi
    {
        if (!$this->specName) {
            throw new MissingSpecException('Cannot resolve schema without target spec.');
        }

        $file = $this->getFile();

        $openapi = null;

        switch (strtolower(substr($this->specName, -4))) {
            case 'json':
                $openapi = Reader::readFromJsonFile($file);
                break;
            case 'yaml':
                $openapi = Reader::readFromYamlFile($file);
                break;
        }

        if (!$openapi) {
            throw new MissingSpecException('The spec source was invalid.');
        }

        return $openapi;
    }

    protected function getFile()
    {
        if (!$source = Arr::get(config('spectator.sources', []), config('spectator.default'))) {
            throw new MissingSpecException('A valid spec source must be defined.');
        }

        if ($source['source'] === 'local') {
            $path = $source['base_folder'];

            if (!Str::endsWith($path, '/')) {
                $path = $path.'/';
            }

            $file = str_replace('/', '', $this->specName);

            $path = realpath("{$path}/{$file}");

            if (!file_exists($path)) {
                throw new MissingSpecException('A valid spec source must be defined.');
            }

            return $path;
        }

        throw new MissingSpecException('A valid spec source was not found.');
    }
}
