<?php

namespace Spectator;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Spectator\Exceptions\MalformedSpecException;
use Spectator\Exceptions\MissingSpecException;
use Throwable;

class RequestFactory
{
    use Macroable;

    public ?Throwable $requestException = null;

    public ?Throwable $responseException = null;

    protected ?string $specName = null;

    protected ?string $pathPrefix = null;

    private array $cachedSpecs = [];

    /**
     * Set the file name of the spec.
     */
    public function using($name)
    {
        $this->specName = $name;
    }

    /**
     * Get the file name of the spec.
     */
    public function getSpec(): ?string
    {
        return $this->specName;
    }

    /**
     * Set the prefix for the API paths.
     */
    public function setPathPrefix($pathPrefix): self
    {
        $this->pathPrefix = $pathPrefix;

        return $this;
    }

    /**
     * Get the prefix for the API paths.
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
        $this->requestException = null;
        $this->responseException = null;
    }

    /**
     * Resolve and parse the spec.
     *
     *
     * @throws \cebe\openapi\exceptions\IOException
     * @throws \cebe\openapi\exceptions\TypeErrorException
     * @throws \cebe\openapi\exceptions\UnresolvableReferenceException
     * @throws \cebe\openapi\json\InvalidJsonPointerSyntaxException
     * @throws MalformedSpecException
     * @throws MissingSpecException
     */
    public function resolve(): OpenApi
    {
        $this->requestException = null;
        $this->responseException = null;

        if ($this->specName) {
            $file = $this->getFile();

            if ($this->cachedSpecs[$file] ?? null) {
                return $this->cachedSpecs[$file];
            }

            try {
                switch (strtolower(pathinfo($this->specName, PATHINFO_EXTENSION))) {
                    case 'json':
                        return $this->cachedSpecs[$file] = Reader::readFromJsonFile($file);
                    case 'yml':
                    case 'yaml':
                        return $this->cachedSpecs[$file] = Reader::readFromYamlFile($file);
                }
            } catch (TypeErrorException $exception) {
                throw new MalformedSpecException('The spec file is invalid. Please lint it using spectral (https://github.com/stoplightio/spectral) before trying again.');
            }
        }

        throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
    }

    /**
     * @return void
     */
    public function captureRequestValidation(Throwable $throwable)
    {
        $this->requestException = $throwable;
    }

    /**
     * @return void
     */
    public function captureResponseValidation(Throwable $throwable)
    {
        $this->responseException = $throwable;
    }

    /**
     * Retrieve the spec file.
     *
     * @throws MissingSpecException
     *
     * @return mixed
     */
    protected function getFile()
    {
        if (!$source = Arr::get(config('spectator.sources', []), config('spectator.default'))) {
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
     */
    protected function getGithubPath(array $source, $file): string
    {
        $path = "https://{$source['token']}@raw.githubusercontent.com/{$source['repo']}/{$source['base_path']}/{$file}";

        return $path;
    }

    /**
     * Standardize a file name.
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
     */
    protected function standardizePath($path): string
    {
        if (!Str::endsWith($path, '/')) {
            $path = $path.'/';
        }

        return $path;
    }
}
