<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation;

/**
 * The things a person can do with a form, named once.
 *
 * These are not widgets a kit invents: they say what the form does, and every
 * form does the same things. A kit decides how they are drawn — a button, a link,
 * a panel — and **where they go is the presentation\'s business**, which is also
 * how they are opted into: a document that does not ask for one draws none.
 *
 * `save` and `confirm` are the two that write. `reset` is the way back to what the
 * form actually holds, and `history` is the way into what it held before — both
 * read-only, both offered only where a document says so, because a page full of
 * tools nobody asked for is a page that says the wrong thing.
 */
final class PresentationActions
{
    public const string SAVE = 'save';

    public const string CONFIRM = 'confirm';

    public const string RESET = 'reset';

    public const string HISTORY = 'history';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SAVE, self::CONFIRM, self::RESET, self::HISTORY];
    }
}
