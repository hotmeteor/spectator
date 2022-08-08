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
    /*
     * @method static void assertValidRequest()
     */
    use Macroable;

    protected ?string $specName = null;

    protected ?string $pathPrefix = null;

    private array $cached_specs = [];

    /**
     * Set the file name of the spec.
     *
     * @param $name
     */
    public function using($name)
    {
        $this->specName = $name;
    }

    /**
     * Get the file name of the spec.
     *
     * @return string|null
     */
    public function getSpec(): ?string
    {
        return $this->specName;
    }

    /**
     * Set the prefix for the API paths.
     *
     * @param $pathPrefix
     * return RequestFactory
     */
    public function setPathPrefix($pathPrefix): self
    {
        $this->pathPrefix = $pathPrefix;

        return $this;
    }

    /**
     * Get the prefix for the API paths.
     *
     * @return string
     */
    public function getPathPrefix(): string
    {
        return $this->pathPrefix ?? config('spectator.path_prefix');
    }

    /**
     * Reset the name of the spec.
     *
     * return RequestFactory
     */
    public function reset(): self
    {
        $this->specName = null;

        return $this;
    }

    /**
     * Resolve and parse the spec.
     *
     * @return OpenApi
     *
     * @throws MissingSpecException
     * @throws \cebe\openapi\exceptions\IOException
     * @throws \cebe\openapi\exceptions\TypeErrorException
     * @throws \cebe\openapi\exceptions\UnresolvableReferenceException
     * @throws \cebe\openapi\json\InvalidJsonPointerSyntaxException
     */
    public function resolve(): OpenApi
    {
        if ($this->specName) {
            $file = $this->getFile();
            if ($this->cached_specs[$file] ?? null) {
                return $this->cached_specs[$file];
            }

            switch (strtolower(pathinfo($this->specName, PATHINFO_EXTENSION))) {
                case 'json':
                    return $this->cached_specs[$file] = Reader::readFromJsonFile($file);
                case 'yml':
                case 'yaml':
                    return $this->cached_specs[$file] = Reader::readFromYamlFile($file);
            }
        }

        throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
    }

    /**
     * Retrieve the spec file.
     *
     * @return mixed
     *
     * @throws MissingSpecException
     */
    protected function getFile()
    {
        if (! $source = Arr::get(config('spectator.sources', []), config('spectator.default'))) {
            throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
        }

        $method = Str::camel("get_{$source['source']}_path");

        if (method_exists($this, $method)) {
            $file = $this->standardizeFileName($this->specName);

            return $this->{$method}($source, $file);
        }

        throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
    }

    /**
     * Retrieve a local spec file.
     *
     * @param  array  $source
     * @param $file
     * @return false|string
     *
     * @throws MissingSpecException
     */
    protected function getLocalPath(array $source, $file)
    {
        $path = $this->standardizePath($source['base_path']);

        $path = realpath("{$path}{$file}");

        if (! file_exists($path)) {
            throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
        }

        return $path;
    }

    /**
     * Retrieve a remote spec file.
     *
     * @param  array  $source
     * @param $file
     * @return string
     */
    protected function getRemotePath(array $source, $file)
    {
        $path = $this->standardizePath($source['base_path']);

        $params = Arr::get($source, 'params', '');

        $path = "{$path}{$file}{$params}";

        return $path;
    }

    /**
     * Build a Github path.
     *
     * @param  array  $source
     * @param $file
     * @return string
     */
    protected function getGithubPath(array $source, $file)
    {
        $path = "https://{$source['token']}@raw.githubusercontent.com/{$source['repo']}/{$source['base_path']}/{$file}";

        return $path;
    }

    /**
     * Standardize a file name.
     *
     * @param $file
     * @return string
     */
    protected function standardizeFileName($file)
    {
        if (Str::startsWith($file, '/')) {
            $file = Str::replaceFirst('/', '', $file);
        }

        return $file;
    }

    /**
     * Standardize a path.
     *
     * @param $path
     * @return mixed|string
     */
    protected function standardizePath($path)
    {
        if (! Str::endsWith($path, '/')) {
            $path = $path.'/';
        }

        return $path;
    }
}
