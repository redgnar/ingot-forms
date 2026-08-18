<?php

declare(strict_types=1);

namespace App\Http\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Takes a submitted value exactly as it arrived — object, list or scalar —
 * without transforming or constraining it.
 *
 * Used for fields whose type this application does not know: their payload
 * round-trips untouched so a definition with plugin fields can still be
 * drafted, while confirmation refuses it outright.
 */
final class RawValueType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => false,
            'required' => false,
        ]);
    }
}
