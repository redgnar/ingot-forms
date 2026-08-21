<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Exception;

use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal says which form it is about — the messages end up in log lines
 * and in the `detail` of a problem document, where "which one" is the first
 * thing anybody asks.
 */
final class FormExceptionsTest extends TestCase
{
    /**
     * @param \Closure(FormId): \RuntimeException $refusal
     */
    #[DataProvider('refusals')]
    public function testTheMessageNamesTheFormAndTheState(\Closure $refusal, string $expected): void
    {
        // GIVEN
        $id = FormId::next();

        // WHEN
        $thrown = $refusal($id);

        // THEN
        self::assertStringContainsString($expected, $thrown->getMessage());
        self::assertStringContainsString((string) $id, $thrown->getMessage());
    }

    /**
     * @return \Generator<string, array{\Closure(FormId): \RuntimeException, string}>
     */
    public static function refusals(): \Generator
    {
        yield 'locked' => [static fn(FormId $id): \RuntimeException => new FormLocked($id), 'can no longer be edited'];
        yield 'already confirmed' => [static fn(FormId $id): \RuntimeException => new FormAlreadyConfirmed($id), 'already confirmed'];
        yield 'no data' => [static fn(FormId $id): \RuntimeException => new FormHasNoData($id), 'no data'];
        yield 'not found' => [static fn(FormId $id): \RuntimeException => new FormNotFound($id), 'does not exist'];
        yield 'gone' => [static fn(FormId $id): \RuntimeException => new FormGone($id), 'has expired'];
        // Not a refusal of anything somebody did: the row is intact and today's
        // rules cannot read it. It still has to say which form, because that is
        // the one thing whoever migrates it needs.
        yield 'unreadable' => [
            static fn(FormId $id): \RuntimeException => new FormUnreadable($id, ErrorReport::of(
                new MappingError(JsonPointer::fromString('/items/0/type'), 'mapping.unknown_variant', 'This type is no longer known.'),
            )),
            'no longer satisfies',
        ];
    }

    public function testValuesNotValidCarriesTheReportAndSaysWhatItIs(): void
    {
        // GIVEN findings from whichever gate refused the values
        $report = ErrorReport::of(new MappingError(JsonPointer::fromString('/age'), 'schema.minimum', 'Too small.'));

        // WHEN
        $exception = new ValuesNotValid($report);

        // THEN the report travels untouched, and the message stands on its own
        self::assertSame($report, $exception->report);
        self::assertSame('Form values are not valid.', $exception->getMessage());
    }
}
