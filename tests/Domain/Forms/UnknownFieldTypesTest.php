<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

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
