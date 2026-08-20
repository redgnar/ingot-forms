<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\UnknownFieldTypes;
use PHPUnit\Framework\TestCase;

final class UnknownFieldTypesTest extends TestCase
{
    public function testADefinitionOfKnownFieldsHasNothingToReport(): void
    {
        // GIVEN
        $definition = new FormDefinition([
            new TextField('email', required: true),
        ]);

        // WHEN
        $report = new UnknownFieldTypes()->in($definition);

        // THEN such a form can be confirmed
        self::assertTrue($report->isEmpty());
    }

    public function testAPluginFieldIsFoundHoweverDeepItIsNested(): void
    {
        // GIVEN a type nobody here knows, inside an entry of an entry
        $definition = new FormDefinition([
            new TextField('customer', required: true),
            new CollectionField('lines', [
                new TextField('sku', required: true),
                new CollectionField('parts', [
                    new GenericField('signature', 'sig'),
                ]),
            ]),
        ]);

        // WHEN
        $report = new UnknownFieldTypes()->in($definition);

        // THEN nesting does not make a contract vouchable: it is reported where
        // it actually sits, so whoever wrote the definition can find it
        self::assertCount(1, $report->errors);
        self::assertSame('/items/1/items/1/items/0/type', $report->errors[0]->pointer->toString());
        self::assertSame('form.data.unknown-field-type', $report->errors[0]->code);
        self::assertSame('signature', $report->errors[0]->input);
    }

    public function testWhatSurroundsACollectionIsStillReportedWithIt(): void
    {
        // GIVEN unknown types before, inside and after a collection
        $definition = new FormDefinition([
            new GenericField('captcha', 'proof'),
            new CollectionField('lines', [new GenericField('signature', 'sig')]),
            new GenericField('fingerprint', 'mark'),
        ]);

        // WHEN
        $report = new UnknownFieldTypes()->in($definition);

        // THEN all three, in reading order: walking into an entry is not a
        // reason to forget what came before it or to stop at what comes after
        self::assertSame(
            ['/items/0/type', '/items/1/items/0/type', '/items/2/type'],
            array_map(static fn($error): string => $error->pointer->toString(), $report->errors),
        );
    }

    public function testACollectionOfKnownItemsIsNotItselfSomethingUnknown(): void
    {
        // GIVEN a collection, which is a type this application does know
        $definition = new FormDefinition([
            new CollectionField('lines', [new TextField('sku', required: true)], min: 1),
        ]);

        // WHEN / THEN a form made of these can be confirmed
        self::assertTrue(new UnknownFieldTypes()->in($definition)->isEmpty());
    }

    public function testEveryPluginFieldIsNamedAtItsPositionInTheDefinition(): void
    {
        // GIVEN two field types this application does not know
        $definition = new FormDefinition([
            new TextField('email', required: true),
            new GenericField('signature', 'sig'),
            new GenericField('captcha', 'proof'),
        ]);

        // WHEN
        $report = new UnknownFieldTypes()->in($definition);

        // THEN each is reported where it sits, naming the type, not the values
        $errors = $report->errors;
        self::assertCount(2, $errors);

        self::assertSame('/items/1/type', $errors[0]->pointer->toString());
        self::assertSame('form.data.unknown-field-type', $errors[0]->code);
        self::assertSame('signature', $errors[0]->input);
        self::assertStringContainsString('"sig"', $errors[0]->message);

        self::assertSame('/items/2/type', $errors[1]->pointer->toString());
        self::assertSame('captcha', $errors[1]->input);
    }
}
