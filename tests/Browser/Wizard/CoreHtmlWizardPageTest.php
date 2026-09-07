<?php

declare(strict_types=1);

namespace App\Tests\Browser\Wizard;

/**
 * Stepping in the plain kit, where the stepper is a few dozen lines of its own
 * module — the bargain that kit was born with.
 */
final class CoreHtmlWizardPageTest extends WizardPageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }
}
