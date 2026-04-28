<?php

namespace Spectator\Console;

use Illuminate\Console\Command;
use Spectator\Exceptions\MalformedSpecException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\RequestFactory;
use Throwable;

class CoverageCommand extends Command
{
    protected $signature = 'spectator:coverage
                            {--spec= : Spec file name (e.g. Api.v1.yml)}
                            {--format=text : Output format: text or json}';

    protected $description = 'List all operations defined in your OpenAPI spec.';

    public function handle(RequestFactory $factory): int
    {
        $spec = $this->option('spec');
        $format = $this->option('format');

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

        $operations = [];

        foreach ($openapi->paths as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'] as $method) {
                if ($pathItem->{$method} !== null) {
                    $operations[] = [
                        'method' => strtoupper($method),
                        'path' => $path,
                        'operationId' => $pathItem->{$method}->operationId ?? null,
                        'summary' => $pathItem->{$method}->summary ?? null,
                    ];
                }
            }
        }

        if ($format === 'json') {
            foreach (explode(PHP_EOL, json_encode([
                'spec' => $specName,
                'operations' => $operations,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        }

        $this->info("Operations in {$specName}:");
        $this->newLine();

        $this->table(
            headers: ['Method', 'Path', 'Operation ID', 'Summary'],
            rows: array_map(
                fn (array $op) => [$op['method'], $op['path'], $op['operationId'] ?? '—', $op['summary'] ?? '—'],
                $operations,
            ),
        );

        $this->newLine();
        $this->line(count($operations).' operation(s) found.');

        return self::SUCCESS;
    }
}
