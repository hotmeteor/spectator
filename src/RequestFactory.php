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
    use Macroable;

    /**
     * @var string|null
     */
    protected ?string $specName = null;

    /**
     * @var string|null
     */
    protected ?string $pathPrefix = null;

    /**
     * @var bool
     */
    protected bool $validateRequest = true;

    /**
     * @var array
     */
    private array $cachedSpecs = [];

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
    public function reset(): void
    {
        $this->specName = null;
    }

    /**
     * @return void
     */
    public function skipRequestValidation(): void
    {
        $this->validateRequest = false;
    }

    /**
     * @return bool
     */
    public function shouldValidateRequest(): bool
    {
        return $this->validateRequest;
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

            if ($this->cachedSpecs[$file] ?? null) {
                return $this->cachedSpecs[$file];
            }

            switch (strtolower(pathinfo($this->specName, PATHINFO_EXTENSION))) {
                case 'json':
                    return $this->cachedSpecs[$file] = Reader::readFromJsonFile($file);
                case 'yml':
                case 'yaml':
                    return $this->cachedSpecs[$file] = Reader::readFromYamlFile($file);
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
     * @return string
     *
     * @throws MissingSpecException
     */
    protected function getLocalPath(array $source, $file): string
    {
        $path = $this->standardizePath($source['base_path']);

        $path = realpath("{$path}{$file}");

        if (file_exists($path)) {
            return $path;
        }

        throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
    }

    /**
     * Retrieve a remote spec file.
     *
     * @param  array  $source
     * @param $file
     * @return string
     */
    protected function getRemotePath(array $source, $file): string
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
    protected function getGithubPath(array $source, $file): string
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
    protected function standardizeFileName($file): string
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
     * @return string
     */
    protected function standardizePath($path): string
    {
        if (! Str::endsWith($path, '/')) {
            $path = $path.'/';
        }

        return $path;
    }
}
