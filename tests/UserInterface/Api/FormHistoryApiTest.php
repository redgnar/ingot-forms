<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testEverySaveIsThereAfterwardsInTheOrderItHappened(): void
    {
        // GIVEN a form saved twice
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 36}');
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 37, "email": "ada@example.com"}');

        // WHEN the history is read
        $this->client->request('GET', \sprintf('/api/forms/%s/history', $id));

        // THEN both saves are there, newest first, numbered per form
        self::assertResponseStatusCodeSame(200);
        $revisions = $this->responseBody()['revisions'] ?? null;
        self::assertIsArray($revisions);
        self::assertCount(2, $revisions);
        self::assertSame([2, 1], array_column($revisions, 'seq'));
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

    public function testASaveThatStoresWhatIsAlreadyStoredIsNotOne(): void
    {
        // GIVEN a form saved once
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 36, "email": "ada@example.com"}');

        // WHEN the same answers arrive again, with their names in another order
        // — which is what putting a version back does when the version is where
        // the form already is
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"email": "ada@example.com", "age": 36}');

        // THEN it was accepted, and it changed nothing: one moment to go back
        // to, not two identical ones
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', \sprintf('/api/forms/%s/history', $id));
        $revisions = $this->responseBody()['revisions'] ?? null;
        self::assertIsArray($revisions);
        self::assertCount(1, $revisions);

        // AND the form still holds it, in the text the save that did happen
        // stored — the second one was not a save, so it did not reorder anything
        $this->client->request('GET', \sprintf('/api/forms/%s/history/1', $id));
        self::assertSame(['age' => 36, 'email' => 'ada@example.com'], $this->responseBody());
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
        self::assertSame([true, false], array_column($revisions, 'confirmed'));
    }

    public function testARevisionSentBackBecomesTheNewestSave(): void
    {
        // GIVEN a form saved twice
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 36}');
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 37, "email": "ada@example.com"}');

        // WHEN the first save is read and sent back — which is all restoring is:
        // an ordinary draft save of a document somebody happens to have read
        $this->client->request('GET', \sprintf('/api/forms/%s/history/1', $id));
        $this->putJson(\sprintf('/api/forms/%s/data', $id), (string) $this->client->getResponse()->getContent());
        self::assertResponseStatusCodeSame(204);

        // THEN the form holds it again...
        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));
        $current = $this->responseBody();
        self::assertSame(['age' => 36], $current);

        // ...and the history grew rather than rewound. Restoring is a change like
        // any other, so it is recorded like any other — and the save it came from
        // is still there to be read again
        $this->client->request('GET', \sprintf('/api/forms/%s/history', $id));
        $history = $this->responseBody();
        $revisions = $history['revisions'] ?? null;
        self::assertIsArray($revisions);
        self::assertSame([3, 2, 1], array_column($revisions, 'seq'));

        $this->client->request('GET', \sprintf('/api/forms/%s/history/3', $id));
        $newest = $this->responseBody();
        self::assertSame($current, $newest);
    }

    public function testPuttingOneAnswerBackNeedsNoEndpointOfItsOwn(): void
    {
        // GIVEN a form where two answers were given and then both changed
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"email": "ada@example.com", "age": 36}');
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"email": "eve@example.com", "age": 40}');

        // WHEN a client takes one member out of the older save and sends the merge
        // — reading hands over a whole document, and picking from it is what a
        // client does before it sends the result
        $this->client->request('GET', \sprintf('/api/forms/%s/history/1', $id));
        $older = $this->responseBody();
        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));
        $merged = [...$this->responseBody(), 'email' => $older['email']];
        $this->putJson(\sprintf('/api/forms/%s/data', $id), json_encode($merged, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        // THEN exactly that answer moved back, and nothing else did
        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));
        self::assertSame(['email' => 'ada@example.com', 'age' => 40], $this->responseBody());
    }

    public function testARevisionNamingAFileNobodyKeptCannotBeRestored(): void
    {
        // GIVEN a form that saved a file and then saved without it
        $id = $this->createFileForm();
        $this->client->request(
            'POST',
            \sprintf('/api/forms/%s/files', $id),
            server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=----ingot'],
            files: ['file' => new UploadedFile($this->temporaryFile('%PDF-1.4 a tiny invoice'), 'invoice.pdf', null, null, true)],
        );
        self::assertResponseStatusCodeSame(201);
        $reference = $this->responseBody();
        $file = $reference['id'];
        self::assertIsString($file);
        $this->putJson(\sprintf('/api/forms/%s/data', $id), json_encode(['invoice' => $reference], \JSON_THROW_ON_ERROR));
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{}');
        self::assertResponseStatusCodeSame(204);

        // AND the bytes are gone, however they went
        $store = self::getContainer()->get(FileStore::class);
        self::assertInstanceOf(FileStore::class, $store);
        $store->delete(FormId::fromString($id), FileId::fromString($file));

        // WHEN the older save is sent back
        $this->client->request('GET', \sprintf('/api/forms/%s/history/1', $id));
        $this->putJson(\sprintf('/api/forms/%s/data', $id), (string) $this->client->getResponse()->getContent());

        // THEN it is refused at the member that is wrong. A document is not more
        // trustworthy for having been accepted once: restoring meets the same
        // gates as anything else, which is the whole reason there is no endpoint
        // that does it for you
        self::assertResponseStatusCodeSame(422);
        $errors = $this->responseBody()['errors'] ?? [];
        self::assertIsArray($errors);
        $first = $errors[0] ?? [];
        self::assertIsArray($first);
        self::assertSame('/invoice/id', $first['pointer'] ?? null);
        self::assertSame('form.file.unknown', $first['code'] ?? null);
    }

    public function testAConfirmedFormTakesNoRestore(): void
    {
        // GIVEN a form saved twice and then confirmed
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 36}');
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"email": "ada@example.com", "age": 36}');
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));
        self::assertResponseStatusCodeSame(204);

        // WHEN an older save is sent back
        $this->client->request('GET', \sprintf('/api/forms/%s/history/1', $id));
        $this->putJson(\sprintf('/api/forms/%s/data', $id), (string) $this->client->getResponse()->getContent());

        // THEN the door is closed for good, restoring included — and the history
        // is still there to read, which is all it ever was
        self::assertResponseStatusCodeSame(409);
        self::assertSame('urn:problem:ingot-forms:form-locked', $this->responseBody()['type']);

        $this->client->request('GET', \sprintf('/api/forms/%s/history', $id));
        self::assertResponseStatusCodeSame(200);
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

    /**
     * A form that asks for a file, for the one case where an old document can
     * stop being acceptable: the rules cannot move (a definition is immutable),
     * but the bytes a document names can go.
     */
    private function createFileForm(): string
    {
        $this->putJson('/api/forms', json_encode([
            'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            'definition' => ['items' => [['type' => 'file', 'name' => 'invoice', 'accept' => ['application/pdf'], 'maxSize' => 4096]]],
        ], \JSON_THROW_ON_ERROR), method: 'POST');
        self::assertResponseStatusCodeSame(201);

        $id = $this->responseBody()['id'] ?? '';
        self::assertIsString($id);

        return $id;
    }

    private function temporaryFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingot-history');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return $path;
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
