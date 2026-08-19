<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Rule;

use App\Domain\Forms\Presentation\PresentationDocument;
use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * A catalogue carried in the document has to be usable: it must say which locale
 * answers by default, and that one must be complete.
 *
 * The other locales may lag behind — that is how translating actually goes, and
 * a client falls back to the default. Codes nobody uses are fine too: a shared
 * catalogue may carry more than one form needs.
 *
 * A document with no catalogue at all is resolved somewhere else entirely, so
 * there is nothing here to check.
 *
 * @implements ObjectValidator<PresentationDocument>
 */
final class TranslationsValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        if ($object->translations === []) {
            return;
        }

        $default = $object->defaultLocale;

        if ($default === null || !isset($object->translations[$default])) {
            $context->addError(
                '/defaultLocale',
                'presentation.locale.unknown',
                'defaultLocale must name one of the locales in translations.',
                $default,
            );

            return;
        }

        foreach ($object->codes() as $code) {
            if (!isset($object->translations[$default][$code])) {
                $context->addError(
                    \sprintf('/translations/%s', $default),
                    'presentation.translation.missing',
                    \sprintf('The default locale has no text for "%s".', $code),
                    $code,
                );
            }
        }
    }
}
