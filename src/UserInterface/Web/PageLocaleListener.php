<?php

declare(strict_types=1);

namespace App\UserInterface\Web;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Which language a page is drawn in, when the reader asked for one.
 *
 * The pages used to carry the locale in the path (`/{_locale}/forms/{id}`), and
 * that is gone. A page's address has to be one shape: whatever stands in front
 * of this service applies its rules to a prefix and reads the form's id out of
 * the segment after it, and an optional first segment moves that id — so a rule
 * written for `/forms/{id}` silently misses `/pl/forms/{id}` rather than
 * breaking on it. The choice of language arrives as `?lang=xx` instead, which no
 * gateway rule has to know about.
 *
 * It is put where the framework already looks for it — the `_locale` request
 * attribute — rather than applied with `setLocale()`, because Symfony's own
 * `LocaleListener` runs after this one and would otherwise overwrite it from
 * `Accept-Language`. So this listener does exactly what the route parameter used
 * to do, one step earlier, and everything downstream is unchanged.
 */
#[AsEventListener(event: RequestEvent::class, priority: 20)]
final class PageLocaleListener
{
    /**
     * A language, optionally a region: the same shape the route requirement
     * stated, kept because it is now the only thing between a query string and
     * a translator. A well-formed locale nobody has a catalogue for falls back
     * the way it always did, which is the framework's business rather than this
     * listener's.
     */
    private const SHAPE = '/^[a-z]{2}(-[A-Z]{2})?$/';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Only the pages negotiate a language; `/api` serves codes and resolves
        // nothing, and `_errors: html` is what already says which side a request
        // is on. Routing has run by this priority, so the attribute is there.
        if ($request->attributes->get('_errors') !== 'html') {
            return;
        }

        $asked = $request->query->get('lang');

        if (\is_string($asked) && preg_match(self::SHAPE, $asked) === 1) {
            $request->attributes->set('_locale', $asked);
        }
    }
}
