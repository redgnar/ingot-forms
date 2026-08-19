<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation;

/**
 * The two things a person can do with a form, named once.
 *
 * These are not widgets a kit invents: they say what the form does, and every
 * form does the same two things. A kit decides how they are drawn — a button, a
 * link, a bar at the bottom — and where they go is the presentation's business.
 */
final class PresentationActions
{
    public const string SAVE = 'save';

    public const string CONFIRM = 'confirm';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SAVE, self::CONFIRM];
    }
}
