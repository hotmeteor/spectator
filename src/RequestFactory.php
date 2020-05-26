<?php

namespace Spectator;

use cebe\openapi\Reader;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use cebe\openapi\SpecObjectInterface;
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

    public function resolve(): SpecObjectInterface
    {
        if (!$this->specName) {
            throw new MissingSpecException('Cannot resolve schema without target spec.');
        }

        $file = $this->getFile();

        $openapi = strtolower(substr($this->specName, -4)) === 'json'
            ? Reader::readFromJsonFile($file)
            : Reader::readFromYamlFile($file);

        if (!$openapi) {
            throw new MissingSpecException('A valid spec source not be found.');
        }

        return $openapi;
    }

    protected function getFile()
    {
        if (!$source = Arr::get(config('spectator.sources', []), config('spectator.default'))) {
            throw new MissingSpecException('A valid spec source must be defined.');
        }

        if ($source['source'] === 'local') {
            $path = $source['folder'];

            if (!Str::endsWith($path, '/')) {
                $path = $path.'/';
            }

            $file = str_replace('/', '', $this->specName);

            return realpath("{$path}/{$file}");
        }

        throw new MissingSpecException('A valid spec source was not found.');
    }
}
