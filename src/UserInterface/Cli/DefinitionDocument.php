<?php

declare(strict_types=1);

namespace App\UserInterface\Cli;

use App\Domain\Forms\DeriveMode;
use Ingot\Error\ErrorReport;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The bits the schema commands share: reading a JSON document from a file or
 * stdin, resolving the mode option, and printing a report the way the API
 * would — one `pointer code message` line per finding.
 */
final class DefinitionDocument
{
    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the path or the JSON cannot be used
     */
    public static function readObject(mixed $path): array
    {
        $decoded = self::decode($path, associative: true);

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('%s must contain a JSON object.', self::label($path)));
        }

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * Values keep JSON's own semantics — objects as \stdClass — because that is
     * what a schema validator expects to see.
     *
     * @throws \RuntimeException when the path or the JSON cannot be used
     */
    public static function readValues(mixed $path): \stdClass
    {
        $decoded = self::decode($path, associative: false);

        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException(\sprintf('%s must contain a JSON object keyed by field name.', self::label($path)));
        }

        return $decoded;
    }

    /**
     * @throws \RuntimeException when the option names no known mode
     */
    public static function mode(mixed $option): DeriveMode
    {
        $mode = \is_string($option) ? DeriveMode::tryFrom($option) : null;

        if ($mode === null) {
            throw new \RuntimeException(\sprintf(
                'Unknown mode "%s" — use %s.',
                \is_scalar($option) ? (string) $option : get_debug_type($option),
                implode(' or ', array_column(DeriveMode::cases(), 'value')),
            ));
        }

        return $mode;
    }

    public static function writeReport(OutputInterface $output, string $title, ErrorReport $report): void
    {
        $output->writeln(\sprintf('<error>%s</error>', $title));

        foreach ($report as $error) {
            $output->writeln(\sprintf(
                '  %s  <comment>%s</comment>  %s',
                $error->pointer->toString() === '' ? '<root>' : $error->pointer->toString(),
                $error->code,
                $error->message,
            ));
        }
    }

    private static function decode(mixed $path, bool $associative): mixed
    {
        $json = self::contents($path);

        try {
            return json_decode($json, $associative, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(\sprintf('%s is not valid JSON: %s', self::label($path), $exception->getMessage()));
        }
    }

    private static function contents(mixed $path): string
    {
        if (!\is_string($path) || $path === '') {
            throw new \RuntimeException('Expected a file path, or "-" to read stdin.');
        }

        if ($path === '-') {
            $stdin = file_get_contents('php://stdin');

            if ($stdin === false || trim($stdin) === '') {
                throw new \RuntimeException('Nothing arrived on stdin.');
            }

            return $stdin;
        }

        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('File "%s" does not exist.', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(\sprintf('File "%s" cannot be read.', $path));
        }

        return $contents;
    }

    private static function label(mixed $path): string
    {
        return $path === '-' ? 'The document on stdin' : \sprintf('File "%s"', \is_scalar($path) ? (string) $path : '?');
    }
}
