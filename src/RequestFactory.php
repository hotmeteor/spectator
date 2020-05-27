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
        if ($this->specName) {
            $file = $this->getFile();

            switch (strtolower(substr($this->specName, -4))) {
                case 'json':
                    return Reader::readFromJsonFile($file);
                    break;
                case 'yaml':
                    return Reader::readFromYamlFile($file);
                    break;
            }
        }

        throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
    }

    protected function getFile()
    {
        if (!$source = Arr::get(config('spectator.sources', []), config('spectator.default'))) {
            throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
        }

        if ($source['source'] === 'local') {
            $path = $source['base_folder'];

            if (!Str::endsWith($path, '/')) {
                $path = $path.'/';
            }

            $file = str_replace('/', '', $this->specName);

            $path = realpath("{$path}/{$file}");

            if (!file_exists($path)) {
                throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
            }

            return $path;
        }

        throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
    }
}
