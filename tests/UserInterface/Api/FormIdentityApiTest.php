<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use App\UserInterface\Api\Request\IdentityIntake;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Who filled a form in, end to end: what a gateway asserts, what gets stored,
 * and which side of the service can read it back.
 *
 * The last part is the one worth having a test for. An actor is kept on the
 * management side by *routing* and nothing else — the fill-side history simply
 * does not carry it — so one person who was let through to a form learns nothing
 * about who else filled it in. That holds for exactly as long as nobody adds the
 * member to the fill-side list for convenience, which is what this pins.
 */
final class FormIdentityApiTest extends WebTestCase
{
    private const string DEFINITION = '{"items": [{"type": "text", "name": "email", "required": true}]}';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAFormRecordsWhoCreatedItFilledItInAndClosedIt(): void
    {
        // GIVEN a form created by one caller, saying nothing about identity —
        // which is `recorded`, because a client that forgets should get the
        // document that keeps more record
        $id = $this->create(asserted: 'crm');

        // WHEN somebody else fills it in and a third person closes it
        $this->send('PUT', \sprintf('/api/forms/%s/data', $id), '{"email": "ada@example.com"}', 'ada');
        self::assertResponseStatusCodeSame(204);
        $this->send('POST', \sprintf('/api/forms/%s/confirm', $id), null, 'owner');
        self::assertResponseStatusCodeSame(204);

        // THEN the form knows the two it keeps by name, and says which mode it is
        // in — "who last changed this" is not among them, because the newest save
        // already answers that
        $envelope = $this->read(\sprintf('/api/manage/forms/%s', $id));
        self::assertSame('recorded', $envelope['identity'] ?? null);
        self::assertSame('crm', $envelope['author'] ?? null);
        self::assertSame('owner', $envelope['confirmedBy'] ?? null);

        // AND the save carries the person who entered it, on the management side
        $managed = $this->revisions(\sprintf('/api/manage/forms/%s/history', $id));
        self::assertCount(1, $managed);
        self::assertSame('ada', $managed[0]['actor'] ?? null);

        // AND the fill side is told when, and nothing about who
        $filling = $this->revisions(\sprintf('/api/forms/%s/history', $id));
        self::assertCount(1, $filling);
        self::assertArrayNotHasKey('actor', $filling[0]);
        self::assertArrayHasKey('savedAt', $filling[0]);
    }

    public function testAnAnonymousFormRecordsNobodyHoweverMuchIsAsserted(): void
    {
        // GIVEN a form that asked to record nobody, created and filled in by a
        // deployment whose proxy asserts an identity on every single request
        $id = $this->create(asserted: 'crm', identity: 'anonymous');
        $this->send('PUT', \sprintf('/api/forms/%s/data', $id), '{"email": "ada@example.com"}', 'ada');
        self::assertResponseStatusCodeSame(204);
        $this->send('POST', \sprintf('/api/forms/%s/confirm', $id), null, 'owner');
        self::assertResponseStatusCodeSame(204);

        // THEN nobody was kept anywhere: not the filler, not the person who
        // closed it, and not whoever created it. Anonymity is a promise, and the
        // only place it can be kept is here.
        $envelope = $this->read(\sprintf('/api/manage/forms/%s', $id));
        self::assertSame('anonymous', $envelope['identity'] ?? null);
        // Present and null, not absent: the member is part of the contract, and
        // what it says is that nobody was recorded.
        self::assertArrayHasKey('confirmedBy', $envelope);
        self::assertNull($envelope['confirmedBy']);

        $managed = $this->revisions(\sprintf('/api/manage/forms/%s/history', $id));
        self::assertCount(1, $managed);
        self::assertArrayHasKey('actor', $managed[0]);
        self::assertNull($managed[0]['actor']);

        // AND the author went the same way, though `crm` was asserted on the
        // creating call. The mode is the creator's own configuration: asking for
        // a form that records nobody is asking for that about oneself too, and
        // nothing is lost by it — the system that created this form has not
        // forgotten that it did.
        self::assertArrayHasKey('author', $envelope);
        self::assertNull($envelope['author']);
    }

    public function testAFormBornHoldingValuesAttributesThemToWhoeverCreatedIt(): void
    {
        // GIVEN a form created holding its first draft — one call, one person, and
        // nobody else has been near it
        $this->send(
            'POST',
            '/api/manage/forms',
            \sprintf(
                '{"expireDate": "%s", "definition": %s, "data": {"email": "ada@example.com"}}',
                new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                self::DEFINITION,
            ),
            'crm',
        );
        self::assertResponseStatusCodeSame(201);
        $id = $this->body()['id'] ?? null;
        self::assertIsString($id);

        // THEN the save it was born with names that same caller
        $managed = $this->revisions(\sprintf('/api/manage/forms/%s/history', $id));
        self::assertCount(1, $managed);
        self::assertSame('crm', $managed[0]['actor'] ?? null);
    }

    public function testAModeNobodyHasIsRefusedWhereTheClientPutIt(): void
    {
        // GIVEN a creation asking for a third mode
        $this->send(
            'POST',
            '/api/manage/forms',
            \sprintf(
                '{"expireDate": "%s", "definition": %s, "identity": "optional"}',
                new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                self::DEFINITION,
            ),
            'crm',
        );

        // THEN it is refused at its own pointer. There are two modes and no third:
        // an "optional" one would make a column mean both "nobody was there" and
        // "somebody was and did not say".
        self::assertResponseStatusCodeSame(422);
        $first = $this->firstError();
        self::assertSame('/identity', $first['pointer'] ?? null);
        self::assertSame('form.identity.unknown', $first['code'] ?? null);
    }

    public function testAnAssertionThatCannotBeAnIdentityStopsTheRequest(): void
    {
        // GIVEN a proxy asserting something unusable — a header carrying a newline
        // is header injection, and this is the last place it can be stopped
        $this->send('POST', '/api/manage/forms', \sprintf(
            '{"expireDate": "%s", "definition": %s}',
            new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            self::DEFINITION,
        ), "crm\nX-Injected: 1");

        // THEN the request is refused rather than attributed to the fallback,
        // which would have hidden a broken proxy for months
        self::assertResponseStatusCodeSame(400);
        self::assertSame('urn:problem:ingot-forms:identity-not-valid', $this->body()['type'] ?? null);
    }

    public function testNobodyMayClaimAnIdentityInABody(): void
    {
        // GIVEN a client that would rather say who it is than be told
        $this->send('POST', '/api/manage/forms', \sprintf(
            '{"expireDate": "%s", "definition": %s, "author": "administrator"}',
            new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            self::DEFINITION,
        ), 'crm');

        // THEN the envelope refuses it, and nothing new was needed to make that
        // true: bodies are closed, so "asserted, never claimed" has a mechanism
        // behind it rather than a paragraph
        self::assertResponseStatusCodeSame(422);
        self::assertSame('request.unexpected_key', $this->firstError()['code'] ?? null);
    }

    private function create(string $asserted, ?string $identity = null): string
    {
        $this->send('POST', '/api/manage/forms', \sprintf(
            '{"expireDate": "%s", "definition": %s%s}',
            new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            self::DEFINITION,
            $identity === null ? '' : \sprintf(', "identity": "%s"', $identity),
        ), $asserted);

        self::assertResponseStatusCodeSame(201);
        $id = $this->body()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function send(string $method, string $url, ?string $content, string $asserted): void
    {
        $server = ['HTTP_' . str_replace('-', '_', strtoupper(IdentityIntake::HEADER)) => $asserted];

        if ($content !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $this->client->request($method, $url, server: $server, content: $content);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);

        return $this->body();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revisions(string $url): array
    {
        $body = $this->read($url);
        $revisions = $body['revisions'] ?? null;
        self::assertIsArray($revisions);

        /** @var list<array<string, mixed>> $revisions */
        return $revisions;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstError(): array
    {
        $errors = $this->body()['errors'] ?? null;
        self::assertIsArray($errors);
        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
