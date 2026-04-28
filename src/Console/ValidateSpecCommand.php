<?php

namespace Spectator\Console;

use Illuminate\Console\Command;
use Spectator\Exceptions\MalformedSpecException;
use Spectator\Exceptions\MissingSpecException;
use Spectator\RequestFactory;
use Throwable;

class ValidateSpecCommand extends Command
{
    protected $signature = 'spectator:validate
                            {--spec= : Spec file name (e.g. Api.v1.yml)}
                            {--format=text : Output format: text or json}';

    protected $description = 'Validate that your OpenAPI spec parses without errors.';

    public function handle(RequestFactory $factory): int
    {
        $spec = $this->option('spec');
        $format = $this->option('format');

        if ($spec) {
            $factory->using($spec);
        }

        $specName = $factory->getSpec();

        if (! $specName) {
            return $this->outputFailure(
                format: $format,
                spec: null,
                message: 'No spec file specified. Use --spec= or call Spectator::using() in your test.',
            );
        }

        try {
            $factory->resolve();
        } catch (MissingSpecException|MalformedSpecException $e) {
            return $this->outputFailure(format: $format, spec: $specName, message: $e->getMessage());
        } catch (Throwable $e) {
            return $this->outputFailure(format: $format, spec: $specName, message: $e->getMessage());
        }

        return $this->outputSuccess(format: $format, spec: $specName);
    }

    private function outputSuccess(string $format, string $spec): int
    {
        if ($format === 'json') {
            foreach (explode(PHP_EOL, json_encode(['valid' => true, 'spec' => $spec, 'errors' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
                $this->line($line);
            }
        } else {
            $this->info("✔ {$spec} is valid.");
        }

        return self::SUCCESS;
    }

    private function outputFailure(string $format, ?string $spec, string $message): int
    {
        if ($format === 'json') {
            foreach (explode(PHP_EOL, json_encode(['valid' => false, 'spec' => $spec, 'errors' => [$message]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
                $this->line($line);
            }
        } else {
            $this->error('✘ '.($spec ?? 'Unknown spec').": {$message}");
        }

        return self::FAILURE;
    }
}
