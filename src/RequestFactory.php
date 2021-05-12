<?php

namespace Spectator;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Spectator\Exceptions\MissingSpecException;

class RequestFactory
{
    /**
     * @method static void assertValidRequest()
     */
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

            switch (strtolower(pathinfo($this->specName, PATHINFO_EXTENSION))) {
                case 'json':
                    return Reader::readFromJsonFile($file);
                case 'yml':
                case 'yaml':
                    return Reader::readFromYamlFile($file);
            }
        }

        throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
    }

    protected function getFile()
    {
        if (! $source = Arr::get(config('spectator.sources', []), config('spectator.default'))) {
            throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
        }

        $method = Str::camel("get_{$source['source']}_path");

        if (method_exists($this, $method)) {
            return $this->{$method}($source, $this->specName);
        }

        throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
    }

    protected function getLocalPath(array $source, $file)
    {
        $path = $this->standardizePath($source['base_path']);

        $path = realpath("{$path}{$file}");

        if (! file_exists($path)) {
            throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
        }

        return $path;
    }

    protected function getRemotePath(array $source, $file)
    {
        $path = $this->standardizePath($source['base_path']);

        $params = Arr::get($source, 'params', '');

        $path = "{$path}{$file}{$params}";

        return $path;
    }

    protected function getGithubPath(array $source, $file)
    {
        $path = "https://{$source['token']}@raw.githubusercontent.com/{$source['repo']}/{$source['base_path']}/{$file}";

        return $path;
    }

    protected function standardizePath($path)
    {
        if (! Str::endsWith($path, '/')) {
            $path = $path.'/';
        }

        return $path;
    }
}
