<?php

namespace Spectator\Concerns;

use cebe\openapi\exceptions\TypeErrorException;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Output\ConsoleOutput;

trait HasExpectations
{
    protected $tableStyle;

    public function expectsFalse()
    {
        return function ($contents, array $exceptions) {
            $exception = $this->exceptionType($contents);

            PHPUnit::assertFalse(
                in_array($exception, $exceptions),
                $this->decodeExceptionMessage($contents)
            );
        };
    }

    public function expectsTrue()
    {
        return function ($contents, array $exceptions) {
            $exception = $this->exceptionType($contents);

            PHPUnit::assertTrue(
                in_array($exception, $exceptions),
                $this->decodeExceptionMessage($contents)
            );
        };
    }

    public function exceptionType()
    {
        return function ($contents) {
            return Arr::get($contents, 'exception');
        };
    }

    protected function decodeExceptionMessage()
    {
        return function ($contents) {
            if (Arr::get($contents, 'exception') === TypeErrorException::class) {
                return 'The spec file is invalid. Please lint it using spectral (https://github.com/stoplightio/spectral) before trying again.';
            }

            $message = trim(Arr::get($contents, 'message'));

            if (isset($contents['errors']) && count($contents['errors']) > 0 && ! config('spectator.suppress_errors')) {
                $output = new ConsoleOutput();

                $table = new Table($output);
                $table->setStyle('borderless');

                $table->setHeaders([
                    new TableCell(
                        $message,
                        [
                            'style' => new TableCellStyle([
                                'cellFormat' => '<error>%s</error>',
                            ]),
                        ],
                    ),
                ]);

                $errors = array_filter($contents['errors'], fn ($item) => $item !== $message);

                foreach ($errors as $error) {
                    $table->addRow(["<fg=red>⨯</> <fg=white>{$error}</>"]);
                }

                $table->render();
            }

            return $message;
        };
    }
}
