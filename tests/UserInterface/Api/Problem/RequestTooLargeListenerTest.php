<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api\Problem;

use App\UserInterface\Api\Problem\ProblemResponseFactory;
use App\UserInterface\Api\Problem\RequestTooLargeListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The answer to a request whose body PHP threw away.
 *
 * Past `post_max_size` there are no parts and no parameters to look at, so every
 * reader downstream would report a request with no file in it — true, and
 * useless. This turns the declared length into the one answer a client can act
 * on, and it must stay out of the way of everything else.
 */
final class RequestTooLargeListenerTest extends TestCase
{
    public function testABodyLargerThanTheDeploymentAcceptsIsRefusedBeforeAnybodyReadsIt(): void
    {
        // GIVEN a request declaring more than PHP will accept
        $event = self::event(['CONTENT_LENGTH' => (string) (UploadedFile::getMaxFilesize() + 1)]);

        // WHEN
        self::listener()($event);

        // THEN
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(413, $response->getStatusCode());
        self::assertStringContainsString('request-too-large', (string) $response->getContent());
    }

    public function testABodyWithinTheLimitIsNobodyElsesBusiness(): void
    {
        // GIVEN a request at exactly the limit
        $event = self::event(['CONTENT_LENGTH' => (string) UploadedFile::getMaxFilesize()]);

        // WHEN
        self::listener()($event);

        // THEN the limit is reachable, and nothing here answers for it
        self::assertNull($event->getResponse());
    }

    public function testARequestThatDeclaresNoLengthIsLeftAlone(): void
    {
        // GIVEN / WHEN
        $event = self::event([]);
        self::listener()($event);

        // THEN
        self::assertNull($event->getResponse());
    }

    public function testAPageIsNoClientOfProblemDocuments(): void
    {
        // GIVEN an oversized request to a route that answers with pages
        $event = self::event(['CONTENT_LENGTH' => (string) (UploadedFile::getMaxFilesize() + 1)]);
        $event->getRequest()->attributes->set('_errors', 'html');

        // WHEN
        self::listener()($event);

        // THEN the web adapter draws its own refusals
        self::assertNull($event->getResponse());
    }

    public function testASubRequestIsNotTheRequestThatWasTooLarge(): void
    {
        // GIVEN / WHEN
        $event = self::event(['CONTENT_LENGTH' => (string) (UploadedFile::getMaxFilesize() + 1)], HttpKernelInterface::SUB_REQUEST);
        self::listener()($event);

        // THEN
        self::assertNull($event->getResponse());
    }

    private static function listener(): RequestTooLargeListener
    {
        return new RequestTooLargeListener(new ProblemResponseFactory());
    }

    /**
     * @param array<string, string> $server
     */
    private static function event(array $server, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(
            new class implements HttpKernelInterface {
                public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
                {
                    throw new \LogicException('Nothing here handles a request.');
                }
            },
            Request::create('/api/forms', 'POST', server: $server),
            $type,
        );
    }
}
