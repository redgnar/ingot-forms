<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation;

use App\Domain\Forms\Definition\Condition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Validation\DerivedSchemaValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A condition is read twice — by the derived schema, which *enforces* it, and by
 * {@see Condition::holds()}, which is what a printed record and a
 * server-rendered page ask so they show the questions that were actually asked.
 *
 * Two readings of one rule are worth having only while they cannot drift, and
 * nothing else would notice if they did: a form would go on validating exactly
 * as its contract says while its own record showed a question nobody was asked.
 * So every reading in the table below is put to both, and they have to agree.
 */
final class ConditionsAgreeWithTheSchemaTest extends KernelTestCase
{
    /**
     * The cast every row shares: one item of each kind a condition can test,
     * declared once so a row is nothing but the condition and the document.
     */
    private const array DECLARED = [
        ['type' => 'checkbox', 'name' => 'flag'],
        ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de']],
        ['type' => 'number', 'name' => 'seats'],
        ['type' => 'text', 'name' => 'note'],
    ];

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function readings(): iterable
    {
        $documents = [
            'nothing answered' => [],
            'the tick on' => ['flag' => true],
            'the tick off' => ['flag' => false],
            'one country' => ['country' => 'pl'],
            'the other' => ['country' => 'de'],
            'a number' => ['seats' => 4],
            'the same number written with a point' => ['seats' => 4.0],
            'another number' => ['seats' => 5],
            'a word' => ['note' => 'x'],
            'a tick and a country' => ['flag' => true, 'country' => 'de'],
        ];

        $conditions = [
            'is' => ['item' => 'flag', 'is' => true],
            'is a word' => ['item' => 'country', 'is' => 'pl'],
            'is a number' => ['item' => 'seats', 'is' => 4],
            'is a number written with a point' => ['item' => 'seats', 'is' => 4.0],
            'isNot' => ['item' => 'country', 'isNot' => 'pl'],
            'isNot a tick' => ['item' => 'flag', 'isNot' => true],
            'in' => ['item' => 'country', 'in' => ['pl', 'de']],
            'notIn' => ['item' => 'country', 'notIn' => ['pl']],
            'answered' => ['item' => 'note', 'answered' => true],
            'unanswered' => ['item' => 'note', 'answered' => false],
            'all' => ['all' => [['item' => 'flag', 'is' => true], ['item' => 'country', 'is' => 'de']]],
            'any' => ['any' => [['item' => 'flag', 'is' => true], ['item' => 'country', 'is' => 'pl']]],
            'none' => ['none' => [['item' => 'flag', 'is' => true], ['item' => 'country', 'is' => 'pl']]],
            'nested' => ['all' => [
                ['any' => [['item' => 'country', 'is' => 'pl'], ['item' => 'country', 'is' => 'de']]],
                ['none' => [['item' => 'flag', 'is' => true]]],
            ]],
        ];

        foreach ($conditions as $what => $condition) {
            foreach ($documents as $given => $values) {
                yield \sprintf('%s, given %s', $what, $given) => [$condition, $values];
            }
        }
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $values
     */
    #[DataProvider('readings')]
    #[TestDox('$_dataName: the schema allows the answer exactly when the condition holds')]
    public function testTheSchemaAllowsTheAnsweredQuestionExactlyWhenTheConditionHolds(array $condition, array $values): void
    {
        // GIVEN a form asking one question under this condition
        $processor = self::service(FormDefinitionProcessor::class);
        $definition = $processor->document($processor->parse([
            'items' => [...self::DECLARED, ['type' => 'text', 'name' => 'asked', 'askedWhen' => $condition]],
        ]));

        $question = $definition->structure()->items[\count(self::DECLARED)]->askedWhen;
        self::assertInstanceOf(Condition::class, $question);

        // WHEN the condition is asked of the document, and the document — with
        // the question answered — is put through the derived schema
        $holds = $question->holds($values);
        $document = json_decode(json_encode([...$values, 'asked' => 'an answer'], \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);

        $accepted = self::service(DerivedSchemaValues::class)
            ->validate($definition->structure(), $document, DeriveMode::Draft, FormId::next())
            ->isEmpty();

        // THEN the two say the same thing. An answer the schema will not carry
        // is a question the page must not draw, and the other way round
        self::assertSame($holds, $accepted, $holds
            ? 'The condition holds, but the schema refuses the answer.'
            : 'The condition does not hold, but the schema carries the answer.');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(string $class): object
    {
        self::bootKernel();
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
