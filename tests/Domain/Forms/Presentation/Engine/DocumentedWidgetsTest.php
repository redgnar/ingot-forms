<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Presentation\Engine;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\DateTimeField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\MultiSelectField;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Presentation\Engine\BootstrapEngine;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\PresentationEngine;
use PHPUnit\Framework\TestCase;

/**
 * The documented vocabulary is the real one.
 *
 * Two documents claim to list every control: the table in `configuring-forms.md`
 * is the index, and `kits.md` is the reference that describes each one. Both are
 * written by hand, and both have quietly fallen behind — a whole item type was
 * missing from the table for as long as `datetime` has existed, and a new widget
 * went in twice before anybody noticed the other document had not moved.
 *
 * Nothing in the pipeline reads Markdown, so this is the only thing that can
 * notice. It exists for the reason `RouteGroupsTest` does: the fact is spread
 * over files that cannot see each other, so a test is the mechanism.
 */
final class DocumentedWidgetsTest extends TestCase
{
    private const string INDEX = __DIR__ . '/../../../../../docs/configuring-forms.md';

    private const string REFERENCE = __DIR__ . '/../../../../../docs/kits.md';

    public function testTheIndexListsWhatTheKitsActuallyDraw(): void
    {
        // GIVEN the table an author reads to find out what may be asked for
        $documented = self::table();

        foreach (self::items() as $type => $item) {
            // WHEN each kind of item is asked of each kit
            $drawn = [
                'core-html' => new CoreHtmlEngine()->controlsFor($item) ?? [],
                'bootstrap' => new BootstrapEngine()->controlsFor($item) ?? [],
            ];

            // THEN the row for it says exactly that, in that order. A widget a
            // document may name and no table mentions is one nobody will use; a
            // widget a table promises and no kit draws is a form refused at
            // creation.
            self::assertArrayHasKey($type, $documented, \sprintf('docs/configuring-forms.md has no row for "%s"', $type));
            self::assertSame($drawn, $documented[$type], \sprintf('the documented controls for "%s" are not the ones drawn', $type));
        }
    }

    public function testEveryControlHasAnEntryInTheReference(): void
    {
        // GIVEN the document that describes each control, markup and all. Some
        // are a section of their own and some are a row in a table — a heading
        // and a two-line description would say the same thing about `heading` —
        // so both count as having been written down.
        $described = implode("\n", array_filter(
            explode("\n", (string) file_get_contents(self::REFERENCE)),
            static fn(string $line): bool => str_starts_with(trim($line), '### ') || str_starts_with(trim($line), '| `'),
        ));

        // WHEN every control both kits draw is looked for
        foreach ([new CoreHtmlEngine(), new BootstrapEngine()] as $engine) {
            foreach (self::vocabularyOf($engine) as $widget) {
                // THEN it is named in a heading of its own or in one it shares.
                // `kits.md` is the one document that claims to list them all,
                // and a control nobody wrote down is a control nobody can use on
                // purpose.
                self::assertStringContainsString(
                    \sprintf('`%s`', $widget),
                    $described,
                    \sprintf('docs/kits.md describes no control called "%s"', $widget),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function vocabularyOf(PresentationEngine $engine): array
    {
        $widgets = [];

        foreach (self::items() as $item) {
            $widgets = [...$widgets, ...($engine->controlsFor($item) ?? [])];
        }

        return array_values(array_unique([
            ...$widgets,
            ...$engine->containers(),
            ...$engine->decorations(),
            ...$engine->actions(),
        ]));
    }

    /**
     * One item of every kind the catalogue has — which is what "every kind of
     * value" means to a kit.
     *
     * @return array<string, Field>
     */
    private static function items(): array
    {
        return [
            'text' => new TextField('a'),
            'select' => new SelectField('b', ['one']),
            'multiselect' => new MultiSelectField('c', ['one']),
            'number' => new NumberField('d'),
            'date' => new DateField('e'),
            'datetime' => new DateTimeField('f'),
            'checkbox' => new CheckboxField('g'),
            'collection' => new CollectionField('h', [new TextField('i')]),
            'file' => new FileField('j', ['application/pdf'], 1024),
        ];
    }

    /**
     * The documented table, as the same shape the engines answer in.
     *
     * @return array<string, array{'core-html': list<string>, bootstrap: list<string>}>
     */
    private static function table(): array
    {
        $rows = [];
        $inside = false;

        foreach (explode("\n", (string) file_get_contents(self::INDEX)) as $line) {
            $line = trim($line);

            // This one table and no other. The document has several with a
            // backticked word in the first cell — one of them even has a row
            // called `text`, which is the reader's text size — and reading them
            // all would have this test comparing the wrong thing to the right
            // one.
            if (str_starts_with($line, '| Item `type` |')) {
                $inside = true;

                continue;
            }

            if ($inside && !str_starts_with($line, '|')) {
                break;
            }

            if (!$inside || preg_match('/^\| `(\w+)` \| (.+?) \| (.+?) \|$/', $line, $found) !== 1) {
                continue;
            }

            $rows[$found[1]] = [
                'core-html' => self::widgets($found[2]),
                'bootstrap' => self::widgets($found[3]),
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function widgets(string $cell): array
    {
        preg_match_all('/`([a-z-]+)`/', $cell, $found);

        return $found[1];
    }
}
