<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

/** The form locked. Nothing may edit it afterwards. */
final readonly class FormConfirmed extends FormEvent {}
