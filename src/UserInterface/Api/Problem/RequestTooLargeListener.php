<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Problem;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Answers a request whose body PHP threw away.
 *
 * Past `post_max_size` PHP discards the *whole* body before any of this code
 * runs: no parts, no parameters, nothing. Every reader downstream would then see
 * a request that simply has no file in it and say so — which is true and
 * useless. Comparing the declared length with the limit turns that into the one
 * answer a client can act on.
 *
 * It runs after routing, so a request to nowhere still gets its 404, and the
 * pages (which take no bodies) are left alone — a person is no client of RFC
 * 9457.
 */
#[AsEventListener(priority: 8)]
final class RequestTooLargeListener
{
    public function __construct(
        private readonly ProblemResponseFactory $factory,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->attributes->get('_errors') === 'html') {
            return;
        }

        $declared = $request->headers->get('Content-Length');
        // The effective ceiling of this deployment: whichever of PHP's two
        // limits is lower. Reading it here rather than configuring it again is
        // what keeps the answer true after somebody edits an ini file.
        $limit = UploadedFile::getMaxFilesize();

        if ($declared === null || $limit <= 0 || (int) $declared <= $limit) {
            return;
        }

        $event->setResponse($this->factory->simple(
            413,
            'request-too-large',
            'The request body is larger than this deployment accepts.',
            \sprintf('The body declares %s bytes; the limit is %d.', $declared, $limit),
        ));
    }
}
