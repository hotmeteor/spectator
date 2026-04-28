<?php

namespace Spectator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spectator\Exceptions\MalformedSpecException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\RequestFactory;
use Throwable;

class StubsCommand extends Command
{
    protected $signature = 'spectator:stubs
                            {--spec= : Spec file name (e.g. Api.v1.yml)}
                            {--output=tests/Contract : Directory to write generated test classes (relative to base path)}
                            {--namespace=Tests\\Contract : Namespace for generated test classes}
                            {--base-class=Tests\\TestCase : Fully-qualified base test class}
                            {--force : Overwrite existing files}';

    protected $description = 'Generate skeleton contract test classes from an OpenAPI spec.';

    public function handle(RequestFactory $factory): int
    {
        $spec = $this->option('spec');
        $output = $this->option('output');
        $namespace = $this->option('namespace');
        $baseClass = $this->option('base-class');
        $force = (bool) $this->option('force');

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

        // Collect and group all operations
        $groups = [];
        foreach ($openapi->paths as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'] as $method) {
                $operation = $pathItem->{$method};
                if ($operation === null) {
                    continue;
                }

                $group = $this->resolveGroup($path, (array) ($operation->tags ?? []));

                $groups[$group][] = [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'operationId' => $operation->operationId ?? null,
                    'summary' => $operation->summary ?? null,
                ];
            }
        }

        if (empty($groups)) {
            $this->warn("No operations found in {$specName}.");

            return self::SUCCESS;
        }

        $outputPath = str_starts_with($output, DIRECTORY_SEPARATOR) || (strlen($output) > 1 && $output[1] === ':')
            ? $output
            : base_path($output);
        File::ensureDirectoryExists($outputPath);

        $written = 0;
        $skipped = 0;

        foreach ($groups as $group => $ops) {
            $className = Str::studly($group).'ContractTest';
            $filePath = $outputPath.DIRECTORY_SEPARATOR.$className.'.php';

            if (File::exists($filePath) && ! $force) {
                $this->line("  <fg=yellow>SKIP</>  {$className}.php (already exists, use --force to overwrite)");
                $skipped++;

                continue;
            }

            $content = $this->generateClass($specName, $className, $ops, $namespace, $baseClass);
            File::put($filePath, $content);

            $this->line("  <fg=green>WRITE</> {$className}.php");
            $written++;
        }

        $this->newLine();
        $this->line("{$written} file(s) written, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * Resolve the group name for an operation.
     * Uses the first tag when available; falls back to the first non-parameter path segment.
     *
     * @param  array<string>  $tags
     */
    private function resolveGroup(string $path, array $tags): string
    {
        if (! empty($tags)) {
            return (string) $tags[0];
        }

        // Walk the path segments and use the first one that is not a route parameter
        $segments = array_filter(explode('/', ltrim($path, '/')));
        foreach ($segments as $segment) {
            if (! Str::startsWith($segment, '{')) {
                return $segment;
            }
        }

        return 'misc';
    }

    /**
     * Derive a unique test method name for the operation.
     * Prefers operationId (converted to snake_case); falls back to method+path slug.
     *
     * @param  array<string>  &$used
     */
    private function methodName(string $method, string $path, ?string $operationId, array &$used): string
    {
        if ($operationId !== null && $operationId !== '') {
            $base = 'test_'.Str::snake(str_replace(['-', ' ', '.'], '_', $operationId));
        } else {
            $pathSlug = (string) preg_replace('/_+/', '_', (string) preg_replace('/[^a-z0-9]/', '_', strtolower($path)));
            $base = 'test_'.strtolower($method).'_'.trim($pathSlug, '_');
        }

        // Ensure uniqueness within the class
        $name = $base;
        $suffix = 2;
        while (in_array($name, $used, true)) {
            $name = $base.'_'.$suffix++;
        }

        $used[] = $name;

        return $name;
    }

    /**
     * Generate a PHP test class as a string.
     *
     * @param  array<array{method: string, path: string, operationId: ?string, summary: ?string}>  $ops
     */
    private function generateClass(
        string $specName,
        string $className,
        array $ops,
        string $namespace,
        string $baseClass,
    ): string {
        $baseShort = str_contains($baseClass, '\\') ? Str::afterLast($baseClass, '\\') : $baseClass;
        $baseUse = str_contains($baseClass, '\\') ? "use {$baseClass};\n" : '';

        $usedNames = [];
        $methodBlocks = [];

        foreach ($ops as $op) {
            $name = $this->methodName($op['method'], $op['path'], $op['operationId'], $usedNames);
            $label = "{$op['method']} {$op['path']}";
            $docComment = $op['summary'] !== null ? "    /** {$op['summary']} */\n" : '';

            $methodBlocks[] = <<<PHP
{$docComment}    public function {$name}(): void
    {
        \$this->markTestIncomplete('Implement: {$label}');
    }
PHP;
        }

        $methods = implode("\n\n", $methodBlocks);

        return <<<PHP
<?php

namespace {$namespace};

use Spectator\Middleware;
use Spectator\Spectator;
{$baseUse}
class {$className} extends {$baseShort}
{
    protected function setUp(): void
    {
        parent::setUp();

        Spectator::using('{$specName}');
    }

{$methods}
}
PHP;
    }
}
