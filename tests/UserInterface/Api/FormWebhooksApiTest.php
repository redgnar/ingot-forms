<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Saying where a form reports itself, over the API.
 *
 * The endpoints are part of the creation document, so they are refused the way
 * the rest of it is: at the envelope, with a pointer at the member that is
 * wrong. And they are immutable like the rest of it, which is why there is no
 * endpoint here that changes them.
 */
final class FormWebhooksApiTest extends WebTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = ['items' => [['type' => 'text', 'name' => 'email']]];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAFormMayNameAnEndpointPerEventAndTheEnvelopeSaysWhich(): void
    {
        // GIVEN a form that reports both things that can happen to it
        $id = $this->create([
            'save' => 'https://receiver.test/saved',
            'confirm' => 'https://receiver.test/confirmed',
        ]);
        self::assertResponseStatusCodeSame(201);

        // WHEN the system that owns it reads it back
        $this->client->request('GET', \sprintf('/api/manage/forms/%s', $id));

        // THEN it is told what it asked for — the management side is the only
        // audience this envelope has, and nothing secret is in it
        self::assertSame(
            ['save' => 'https://receiver.test/saved', 'confirm' => 'https://receiver.test/confirmed'],
            $this->body()['webhooks'],
        );
    }

    public function testEitherEventMayBeNamedAloneAndAFormMayNameNeither(): void
    {
        // GIVEN a form that only wants to hear about being finished
        $confirmOnly = $this->create(['confirm' => 'https://receiver.test/confirmed']);
        $this->client->request('GET', \sprintf('/api/manage/forms/%s', $confirmOnly));

        // THEN the other member is null rather than absent: the envelope's shape
        // does not depend on what a client happened to send
        self::assertSame(['save' => null, 'confirm' => 'https://receiver.test/confirmed'], $this->body()['webhooks']);

        // GIVEN a form that says nothing about any of this — the default
        $silent = $this->create(null);
        $this->client->request('GET', \sprintf('/api/manage/forms/%s', $silent));

        // THEN nobody is told anything about it
        self::assertSame(['save' => null, 'confirm' => null], $this->body()['webhooks']);
    }

    /**
     * @param non-empty-string $said
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusable')]
    public function testAnEndpointNothingCouldBeToldAtIsRefusedWhereItWasWritten(string $said, string $code): void
    {
        // GIVEN a creation request naming something that cannot be an endpoint
        // WHEN it is sent
        $this->create(['save' => $said]);

        // THEN the form is not created, and the client is told which member and
        // why — the same shape every other envelope refusal has
        self::assertResponseStatusCodeSame(422);
        $error = $this->firstError();
        self::assertSame('/webhooks/save', $error['pointer']);
        self::assertSame($code, $error['code']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusable(): iterable
    {
        yield 'not a URL at all' => ['receiver.test/saved', 'form.webhook.not_a_url'];
        yield 'a scheme nothing listens on' => ['ftp://receiver.test/saved', 'form.webhook.not_a_url'];
        yield 'longer than allowed' => ['https://receiver.test/' . str_repeat('a', 2000), 'form.webhook.too_long'];
    }

    public function testAMemberNobodyDeclaredIsAClientBugWorthReporting(): void
    {
        // GIVEN a request inventing an event
        // WHEN
        $this->create(['delete' => 'https://receiver.test/deleted']);

        // THEN the closed envelope refuses it, so a client learns about its
        // typo instead of quietly being told about nothing
        self::assertResponseStatusCodeSame(422);
        self::assertSame('request.unexpected_key', $this->firstError()['code']);
    }

    /**
     * @param array<string, string>|null $webhooks
     */
    private function create(?array $webhooks): string
    {
        $request = [
            'expireDate' => new \DateTimeImmutable('+1 day')->format(\DATE_RFC3339),
            'definition' => self::DEFINITION,
        ];

        if ($webhooks !== null) {
            $request['webhooks'] = $webhooks;
        }

        $this->client->request(
            'POST',
            '/api/manage/forms',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($request, \JSON_THROW_ON_ERROR),
        );

        $body = $this->body();

        return \is_string($body['id'] ?? null) ? $body['id'] : '';
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

        /** @var array<string, mixed> */
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
