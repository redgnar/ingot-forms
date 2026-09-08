<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * Every variable this service reads is documented in both places that claim to
 * list them.
 *
 * `.env.dist` is what a clone runs on and what somebody copies; the Operations
 * chapter of `docs/architecture.md` is what whoever deploys this reads. A
 * variable missing from the first is one the next person discovers from a stack
 * trace; missing from the second, one nobody knows they may set — which is how
 * `FORMS_SKIN` and `FORMS_WEBHOOK_TIMEOUT` came to be readable, documented for a
 * developer and invisible to a deployment.
 *
 * Both directions, because a variable nothing reads is a lie of the same size as
 * an undocumented one. Nothing in the pipeline reads Markdown or dotenv files,
 * so this is the only thing that can notice — the reason
 * {@see \App\Tests\Domain\Forms\Presentation\Engine\DocumentedWidgetsTest} exists,
 * applied to the other document written by hand.
 */
final class DocumentedConfigurationTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /**
     * The variables this service defines for itself. `APP_*`, `DATABASE_URL` and
     * `MESSENGER_TRANSPORT_DSN` are the framework's and are documented where the
     * framework documents them.
     */
    private const string OURS = '/\b(?:FORMS|FILES)_[A-Z_]+/';

    public function testEveryVariableTheCodeReadsIsInBothDocuments(): void
    {
        // GIVEN the variables named in the code and in the container config
        $read = self::found(self::everything());

        self::assertNotEmpty($read, 'no variables found at all — this test has stopped looking where they are');

        $dist = (string) file_get_contents(self::ROOT . '/.env.dist');
        $operations = (string) file_get_contents(self::ROOT . '/docs/architecture.md');

        foreach ($read as $variable) {
            // WHEN each is looked for where somebody would look for it
            // THEN both documents have it
            self::assertStringContainsString($variable, $dist, \sprintf('%s is read but not in .env.dist', $variable));
            self::assertStringContainsString(
                $variable,
                $operations,
                \sprintf('%s is read but not in docs/architecture.md, which is what a deployment reads', $variable),
            );
        }
    }

    public function testEveryVariableDocumentedInEnvDistIsOneWeRead(): void
    {
        // GIVEN what `.env.dist` offers somebody to set
        $offered = self::found([(string) file_get_contents(self::ROOT . '/.env.dist')]);
        $read = self::found(self::everything());

        foreach ($offered as $variable) {
            // WHEN each is looked for in the code
            // THEN something reads it. A variable nothing reads is worse than an
            // undocumented one: it is configuration somebody will set and watch
            // do nothing.
            self::assertContains($variable, $read, \sprintf('%s is documented in .env.dist and nothing reads it', $variable));
        }
    }

    /**
     * Every file a variable can be named in: the code and the container config.
     *
     * @return list<string>
     */
    private static function everything(): array
    {
        return [
            ...self::sources(self::ROOT . '/src', '*.php'),
            ...self::sources(self::ROOT . '/config', '*.yaml'),
        ];
    }

    /**
     * @param list<string> $texts
     *
     * @return list<string>
     */
    private static function found(array $texts): array
    {
        $names = [];

        foreach ($texts as $text) {
            preg_match_all(self::OURS, $text, $matches);
            $names = [...$names, ...$matches[0]];
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function sources(string $directory, string $pattern): array
    {
        $texts = [];
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)),
            '/' . str_replace(['.', '*'], ['\.', '.*'], $pattern) . '$/',
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $texts[] = (string) file_get_contents($file->getPathname());
        }

        return $texts;
    }
}
