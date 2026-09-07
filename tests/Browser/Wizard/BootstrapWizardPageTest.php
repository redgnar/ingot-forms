<?php

declare(strict_types=1);

namespace App\Tests\Browser\Wizard;

/**
 * And in the richer kit, where it is a Stimulus controller listening for the
 * moment the conditions were asked again.
 */
final class BootstrapWizardPageTest extends WizardPageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }
}
