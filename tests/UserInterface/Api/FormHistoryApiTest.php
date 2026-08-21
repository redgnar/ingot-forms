<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A form's history over HTTP: the saves it has had, and any one of them read back
 * as it was stored.
 *
 * What is pinned here is mostly what history is *not*: not a way around expiry,
 * not a way into a form that does not exist, and not a way to put anything back —
 * that last one is an ordinary `PUT …/data` and nothing else.
 */
final class FormHistoryApiTest extends WebTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = [
        'items' => [
            ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 120],
            ['type' => 'number', 'name' => 'age', 'min' => 18, 'max' => 120],
        ],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testEverySaveIsThereAfterwardsInTheOrderItHappened(): void
    {
        // GIVEN a form saved twice
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 36}');
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 37, "email": "ada@example.com"}');

        // WHEN the history is read
        $this->client->request('GET', \sprintf('/api/forms/%s/history', $id));

        // THEN both saves are there, oldest first, numbered per form
        self::assertResponseStatusCodeSame(200);
        $revisions = $this->responseBody()['revisions'] ?? null;
        self::assertIsArray($revisions);
        self::assertCount(2, $revisions);
        self::assertSame([1, 2], array_column($revisions, 'seq'));
        self::assertSame([false, false], array_column($revisions, 'confirmed'));
        $first = $revisions[0];
        self::assertIsArray($first);
        self::assertIsString($first['savedAt'] ?? null);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $first['savedAt']);

        // AND each one hands back exactly the document that save stored
        $this->client->request('GET', \sprintf('/api/forms/%s/history/1', $id));
        self::assertSame(['age' => 36], $this->responseBody());
        $this->client->request('GET', \sprintf('/api/forms/%s/history/2', $id));
        self::assertSame(['age' => 37, 'email' => 'ada@example.com'], $this->responseBody());
    }

    public function testAFormBornADraftAlreadyHasASave(): void
    {
        // GIVEN a form created holding values
        $id = $this->createForm(['age' => 36]);

        // WHEN
        $this->client->request('GET', \sprintf('/api/forms/%s/history', $id));

        // THEN its first draft is its first save: a history cannot start shorter
        // than the form
        $revisions = $this->responseBody()['revisions'] ?? null;
        self::assertIsArray($revisions);
        self::assertCount(1, $revisions);
    }

    public function testAFormNobodyFilledInHasAnEmptyHistoryRatherThanNone(): void
    {
        // GIVEN / WHEN
        $this->client->request('GET', \sprintf('/api/forms/%s/history', $this->createForm()));

        // THEN nothing happened yet, which is not an error
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['revisions' => []], $this->responseBody());
    }

    public function testTheSaveAConfirmationLockedIsMarked(): void
    {
        // GIVEN a form saved twice and then confirmed
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 36}');
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"email": "ada@example.com", "age": 36}');
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));
        self::assertResponseStatusCodeSame(204);

        // WHEN
        $this->client->request('GET', \sprintf('/api/forms/%s/history', $id));

        // THEN the last save is the one that got locked, and confirming added no
        // save of its own — it stored nothing
        $revisions = $this->responseBody()['revisions'] ?? null;
        self::assertIsArray($revisions);
        self::assertSame([false, true], array_column($revisions, 'confirmed'));
    }

    public function testASaveThatNeverHappenedIsNotThere(): void
    {
        // GIVEN a form saved once
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 36}');

        // WHEN
        $this->client->request('GET', \sprintf('/api/forms/%s/history/7', $id));

        // THEN
        self::assertResponseStatusCodeSame(404);
        self::assertSame('urn:problem:ingot-forms:revision-not-found', $this->responseBody()['type']);
    }

    public function testThereIsNoHistoryOfAFormThatIsNotThere(): void
    {
        // GIVEN / WHEN
        $unknown = Uuid::v7()->toRfc4122();
        $this->client->request('GET', \sprintf('/api/forms/%s/history', $unknown));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', \sprintf('/api/forms/%s/history/1', $unknown));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnExpiredFormAnswersGoneToBothReads(): void
    {
        // GIVEN a form already past its date, which did save something once
        // (planted directly — the API refuses to create one)
        $id = FormId::next();
        $repository = self::getContainer()->get(DoctrineFormRepository::class);
        self::assertInstanceOf(DoctrineFormRepository::class, $repository);
        $repository->add(new Form($id, $this->definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));

        // WHEN / THEN a history is not a way to read a form the API treats as gone
        $this->client->request('GET', \sprintf('/api/forms/%s/history', $id));
        self::assertResponseStatusCodeSame(410);

        $this->client->request('GET', \sprintf('/api/forms/%s/history/1', $id));
        self::assertResponseStatusCodeSame(410);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function createForm(?array $data = null): string
    {
        $payload = [
            'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            'definition' => self::DEFINITION,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        $this->putJson('/api/forms', json_encode($payload, \JSON_THROW_ON_ERROR), method: 'POST');
        self::assertResponseStatusCodeSame(201);

        $id = $this->responseBody()['id'] ?? '';
        self::assertIsString($id);

        return $id;
    }

    private function definition(): Definition
    {
        $processor = self::getContainer()->get(FormDefinitionProcessor::class);
        self::assertInstanceOf(FormDefinitionProcessor::class, $processor);

        return $processor->document($processor->parse(self::DEFINITION));
    }

    private function putJson(string $url, string $content, string $method = 'PUT'): void
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: $content);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseBody(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
