<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

/** What somebody filled in was stored; it may be overwritten until the form is confirmed. */
final readonly class DraftSaved extends FormEvent {}
