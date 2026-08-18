<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

/** A form came into existence, with its definition and its expire date fixed. */
final readonly class FormCreated extends FormEvent {}
