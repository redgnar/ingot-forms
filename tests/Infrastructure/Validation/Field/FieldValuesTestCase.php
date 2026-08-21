<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Validation\DerivedSchemaValues;
use App\Infrastructure\Validation\SymfonyFormValues;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What one kind of item accepts as a value, judged the way production judges
 * it: through the whole staged validator, in both modes.
 *
 * Every item type answers the same two questions — what verdict a value gets,
 * and whether the two gates behind that verdict would have said the same thing
 * on their own — so a new type is a subclass with one table.
 */
abstract class FieldValuesTestCase extends KernelTestCase
{
    private ValuesValidator $values;

    private DerivedSchemaValues $schema;

    private SymfonyFormValues $form;

    /**
     * The definition these values are judged against.
     *
     * @return array<string, mixed>
     */
    abstract protected static function document(): array;

    /**
     * One row per value worth pinning: the mode it is submitted in, the values
     * document, and the finding it must produce — a pointer and a code, or
     * null when the value is to be accepted.
     *
     * @return iterable<string, array{DeriveMode, string, string|null, string|null}>
     */
    abstract public static function verdicts(): iterable;

    /**
     * Which form the values are judged as. A fresh one is right for every type
     * whose rules are about the value alone; an item whose value *points* at
     * something the form owns overrides this, because then the form has to be
     * the one that owns it.
     */
    protected function formId(): FormId
    {
        return FormId::next();
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->values = self::service(ValuesValidator::class);
        $this->schema = self::service(DerivedSchemaValues::class);
        $this->form = self::service(SymfonyFormValues::class);
    }

    #[DataProvider('verdicts')]
    public function testTheValueGetsItsVerdict(DeriveMode $mode, string $json, ?string $pointer, ?string $code): void
    {
        // GIVEN a form built from this definition
        $definition = self::definition();

        // WHEN the values are judged
        try {
            $this->values->assertFit($definition, self::values($json), $mode, $this->formId());
            $refusal = null;
        } catch (ValuesNotValid $exception) {
            $refusal = $exception->report->errors[0];
        }

        // THEN
        if ($code === null) {
            self::assertNull($refusal, \sprintf('Expected the value to be accepted, got %s.', $refusal->code ?? ''));

            return;
        }

        self::assertNotNull($refusal, 'Expected the value to be refused.');
        self::assertSame($code, $refusal->code);
        self::assertSame($pointer, $refusal->pointer->toString());
    }

    #[DataProvider('verdicts')]
    public function testTheFormNeverRefusesWhatThePublishedSchemaAccepts(DeriveMode $mode, string $json, ?string $pointer, ?string $code): void
    {
        // GIVEN the two engines behind a verdict
        $structure = self::definition()->structure();
        $values = self::values($json);

        // WHEN each is asked on its own
        $publishedContractAccepts = $this->schema->validate($structure, $values, $mode, $this->formId())->isEmpty();
        $formAccepts = $this->form->validate($structure, $values, $mode)->isEmpty();

        // THEN the form may not refuse what the schema accepts: a server
        // stricter than its own published contract fails requests a client had
        // every reason to believe were valid. The other direction is fine —
        // the schema runs first, and it is what clients were told.
        self::assertTrue(
            !$publishedContractAccepts || $formAccepts,
            'The form refuses a value the published schema accepts.',
        );
    }

    final protected static function definition(): Definition
    {
        $processor = self::service(FormDefinitionProcessor::class);

        return $processor->document($processor->parse(static::document()));
    }

    final protected static function values(string $json): \stdClass
    {
        $values = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $values);

        return $values;
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
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
