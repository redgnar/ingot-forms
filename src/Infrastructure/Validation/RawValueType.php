<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Takes a submitted value exactly as it arrived — object, list or scalar —
 * without transforming or constraining it.
 *
 * Used where the form has nothing to add: an item whose type this application
 * does not know (whose payload round-trips untouched so a definition with plugin
 * fields can still be drafted, while confirmation refuses it outright), and a
 * collection, whose every rule is already in the published schema.
 */
final class RawValueType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => false,
            'required' => false,
            // A JSON list arrives as a PHP array, and a simple field refuses one
            // outright (`Form::submit`) unless it says it may hold several
            // values. Taking what arrived is this type's whole job, so it says
            // so — otherwise the form would refuse a list the published schema
            // accepts, which is the one thing these gates may never do.
            'multiple' => true,
        ]);
    }
}
