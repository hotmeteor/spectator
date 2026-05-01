<?php

namespace Spectator\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Spectator\Exceptions\MalformedSpecException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\RequestFactory;
use Throwable;

class RoutesCommand extends Command
{
    protected $signature = 'spectator:routes
                            {--spec= : Spec file name (e.g. Api.v1.yml)}
                            {--format=text : Output format: text or json}
                            {--prefix= : Only consider Laravel routes whose URI starts with this prefix (e.g. api/v2)}
                            {--middleware= : Only consider Laravel routes that have this middleware (name or class)}';

    protected $description = 'Compare spec operations against registered Laravel routes.';

    public function handle(RequestFactory $factory): int
    {
        $spec = $this->option('spec');
        $format = $this->option('format');
        $prefix = $this->option('prefix');
        $middleware = $this->option('middleware');

        if ($spec) {
            $factory->using($spec);
        }

        $specName = $factory->getSpec();

        if (! $specName) {
            $this->error('No spec file specified. Use --spec= or call Spectator::using() in your test.');

            return self::FAILURE;
        }

        try {
            $openapi = $factory->resolve();
        } catch (MissingSpecException|MalformedSpecException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $normalisedPrefix = ($prefix !== null && trim($prefix, '/') !== '') ? trim($prefix, '/') : null;

        // Build a normalised set of all Laravel route keys: "METHOD /path/{_}"
        $laravelRoutes = $this->collectLaravelRoutes($normalisedPrefix, $middleware);

        // Enumerate spec operations
        $specOps = [];
        foreach ($openapi->paths as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'] as $method) {
                if ($pathItem->{$method} !== null) {
                    // Prepend the configured path prefix so the resolved path matches the
                    // actual Laravel route URI (e.g. "/api/users" not "/users").
                    $resolvedPath = $this->resolvePath($path, $factory->getPathPrefix());
                    $normalised = strtoupper($method).' '.$this->normalizePath($resolvedPath);
                    $specOps[] = [
                        'method' => strtoupper($method),
                        'path' => $path,
                        'resolved' => $resolvedPath,
                        'normalised' => $normalised,
                        'matched' => isset($laravelRoutes[$normalised]),
                    ];
                }
            }
        }

        // Find undocumented routes: Laravel routes with no matching spec operation
        $specNormalised = array_column($specOps, 'normalised');
        $undocumented = [];
        foreach ($laravelRoutes as $routeKey => $uri) {
            if (! in_array($routeKey, $specNormalised, true)) {
                $undocumented[] = $routeKey;
            }
        }
        sort($undocumented);

        $filters = array_filter([
            'prefix' => $normalisedPrefix,
            'middleware' => $middleware,
        ], fn (?string $value) => $value !== null && $value !== '');

        if ($format === 'json') {
            return $this->outputJson($specName, $specOps, $undocumented, $filters);
        }

        return $this->outputText($specName, $specOps, $undocumented, $filters);
    }

    /**
     * Collect all registered Laravel routes as a normalised map.
     * Key: "METHOD /normalised/path/{_}"
     * Value: the original URI for display.
     *
     * @return array<string, string>
     */
    private function collectLaravelRoutes(?string $normalisedPrefix, ?string $middleware): array
    {
        $routes = [];

        /** @var Route $route */
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! $this->routePassesFilters($route, $normalisedPrefix, $middleware)) {
                continue;
            }

            $uri = Str::start($route->uri(), '/');

            foreach ($route->methods() as $method) {
                // Laravel adds HEAD for every GET — skip to avoid duplicates
                if ($method === 'HEAD') {
                    continue;
                }

                $key = strtoupper($method).' '.$this->normalizePath($uri);
                $routes[$key] = $uri;
            }
        }

        return $routes;
    }

    private function routePassesFilters(Route $route, ?string $normalisedPrefix, ?string $middleware): bool
    {
        if ($normalisedPrefix !== null) {
            $uri = ltrim($route->uri(), '/');
            if (
                $normalisedPrefix !== ''
                && $uri !== $normalisedPrefix
                && ! str_starts_with($uri, $normalisedPrefix.'/')
            ) {
                return false;
            }
        }

        if ($middleware !== null && $middleware !== '') {
            $declared = $route->middleware();
            // gatherMiddleware() expands group aliases (e.g. 'api') into resolved class names.
            // Checking both lets users filter by either the alias they wrote or a fully-qualified class.
            $gathered = $route->gatherMiddleware();

            // Match by exact string or by base name before ':' to support parameterized
            // middleware like 'throttle:60,1' when the user filters by 'throttle'.
            $hasMiddleware = static function (array $list) use ($middleware): bool {
                foreach ($list as $entry) {
                    if ($entry === $middleware) {
                        return true;
                    }
                    $colonPos = strpos($entry, ':');
                    if ($colonPos !== false && substr($entry, 0, $colonPos) === $middleware) {
                        return true;
                    }
                }

                return false;
            };

            if (! $hasMiddleware($declared) && ! $hasMiddleware($gathered)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalise a path by replacing all route parameters with {_} so that
     * positional comparison is possible regardless of parameter names.
     *
     * Handles both required {param} and optional {param?} variants.
     */
    private function normalizePath(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{_}', $path) ?? $path;
    }

    /**
     * Prepend the spectator path prefix to a spec path, mirroring Middleware::resolvePath().
     */
    private function resolvePath(string $path, string $prefix): string
    {
        $separator = '/';

        $parts = array_filter(array_map(
            fn (string $part) => trim($part, $separator),
            [$prefix, $path],
        ));

        return $separator.implode($separator, $parts);
    }

    /**
     * @param  array<array{method: string, path: string, resolved: string, normalised: string, matched: bool}>  $specOps
     * @param  array<string>  $undocumented
     * @param  array<string, string>  $filters
     */
    private function outputText(string $specName, array $specOps, array $undocumented, array $filters): int
    {
        $this->info("Route comparison for {$specName}:");

        if ($filters !== []) {
            $this->line('Filters:');
            foreach ($filters as $key => $value) {
                $this->line("  {$key}={$value}");
            }
        }

        $this->newLine();

        $rows = [];

        foreach ($specOps as $op) {
            $rows[] = [
                $op['matched'] ? '<fg=green>matched</>' : '<fg=red>unimplemented</>',
                $op['method'],
                $op['resolved'],
            ];
        }

        foreach ($undocumented as $key) {
            [$method, $path] = explode(' ', $key, 2);
            $rows[] = ['<fg=yellow>undocumented</>', $method, $path];
        }

        $this->table(['Status', 'Method', 'Path'], $rows);

        $matched = count(array_filter($specOps, fn ($op) => $op['matched']));
        $unimplemented = count($specOps) - $matched;

        $this->newLine();
        $this->line(sprintf(
            '%d matched, %d unimplemented, %d undocumented.',
            $matched,
            $unimplemented,
            count($undocumented),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<array{method: string, path: string, resolved: string, normalised: string, matched: bool}>  $specOps
     * @param  array<string>  $undocumented
     * @param  array<string, string>  $filters
     */
    private function outputJson(string $specName, array $specOps, array $undocumented, array $filters): int
    {
        $payload = [
            'spec' => $specName,
            'filters' => (object) $filters,
            'spec_operations' => array_map(fn ($op) => [
                'method' => $op['method'],
                'path' => $op['resolved'],
                'status' => $op['matched'] ? 'matched' : 'unimplemented',
            ], $specOps),
            'undocumented_routes' => array_values(array_map(function (string $key) {
                [$method, $path] = explode(' ', $key, 2);

                return ['method' => $method, 'path' => $path];
            }, $undocumented)),
        ];

        foreach (explode(PHP_EOL, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
