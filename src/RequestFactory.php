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

    /** @var array<string, \cebe\openapi\spec\OpenApi> */
    private array $cachedSpecs = [];

    /**
     * Set the file name of the spec.
     */
    public function using(?string $name): self
    {
        $this->specName = $name;

        return $this;
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
    public function setPathPrefix(?string $pathPrefix): self
    {
        $this->pathPrefix = $pathPrefix;

        return $this;
    }

    /**
     * Get the prefix for the API paths.
     */
    public function getPathPrefix(): string
    {
        return $this->pathPrefix ?? config('spectator.path_prefix') ?? '';
    }

    /**
     * Reset the name of the spec.
     */
    public function reset(): void
    {
        $this->specName = null;
        $this->pathPrefix = null;
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
            } catch (TypeErrorException) {
                throw new MalformedSpecException('The spec file is invalid. Please lint it using spectral (https://github.com/stoplightio/spectral) before trying again.');
            }
        }

        throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
    }

    public function captureRequestValidation(Throwable $throwable): void
    {
        $this->requestException = $throwable;
    }

    public function captureResponseValidation(Throwable $throwable): void
    {
        $this->responseException = $throwable;
    }

    /**
     * Retrieve the spec file.
     *
     * @throws MissingSpecException
     */
    protected function getFile(): string
    {
        if (! $source = Arr::get(config('spectator.sources', []), config('spectator.default'))) {
            throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.');
        }

        $file = $this->standardizeFileName($this->specName);

        return match ($source['source']) {
            'local' => $this->getLocalPath($source, $file),
            'remote' => $this->getRemotePath($source, $file),
            'github' => $this->getGithubPath($source, $file),
            default => throw new MissingSpecException('Cannot resolve schema with missing or invalid spec.'),
        };
    }

    /**
     * Retrieve a local spec file.
     *
     * @param  array<string, string>  $source
     *
     * @throws MissingSpecException
     */
    protected function getLocalPath(array $source, string $file): string
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
     * @param  array<string, string>  $source
     */
    protected function getRemotePath(array $source, string $file): string
    {
        $path = $this->standardizePath($source['base_path']);

        $params = Arr::get($source, 'params', '');

        return "{$path}{$file}{$params}";
    }

    /**
     * Build a Github path.
     *
     * @param  array<string, string>  $source
     */
    protected function getGithubPath(array $source, string $file): string
    {
        return "https://{$source['token']}@raw.githubusercontent.com/{$source['repo']}/{$source['base_path']}/{$file}";
    }

    /**
     * Standardize a file name.
     */
    protected function standardizeFileName(string $file): string
    {
        if (Str::startsWith($file, '/')) {
            $file = Str::replaceFirst('/', '', $file);
        }

        return $file;
    }

    /**
     * Standardize a path.
     */
    protected function standardizePath(string $path): string
    {
        if (! Str::endsWith($path, '/')) {
            $path = $path.'/';
        }

        return $path;
    }
}
