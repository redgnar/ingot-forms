<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * The two endpoints a file needs before a form can name one: bytes in, and the
 * way back out for bytes nobody kept.
 *
 * What is pinned here is the whole round trip a client actually makes — upload,
 * echo the answer into the values, and from then on the file is the form's — plus
 * every guard, because each of them is a rule that already existed somewhere and
 * this is where a client meets it.
 */
final class FileApiTest extends WebTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = [
        'items' => [
            ['type' => 'file', 'name' => 'invoice', 'accept' => ['application/pdf'], 'maxSize' => 4096],
        ],
    ];

    private KernelBrowser $client;

    /** @var list<string> */
    private array $temporary = [];

    /** @var list<FormId> */
    private array $used = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        $store = self::getContainer()->get(FileStore::class);

        if ($store instanceof FileStore) {
            foreach ($this->used as $form) {
                $store->forget($form);
            }
        }

        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testAnUploadAnswersWithExactlyWhatTheValuesMayHold(): void
    {
        // GIVEN a form asking for a file
        $id = $this->createForm();

        // WHEN bytes are uploaded to it
        $this->upload($id, 'invoice.pdf', '%PDF-1.4 a tiny invoice');

        // THEN the answer is the description of what was stored, and where to
        // fetch it once the form names it
        self::assertResponseStatusCodeSame(201);
        $reference = $this->responseBody();
        self::assertSame(['id', 'name', 'size', 'type'], array_keys($reference));
        self::assertSame('invoice.pdf', $reference['name']);
        self::assertSame(23, $reference['size']);
        self::assertSame('application/pdf', $reference['type']);
        self::assertIsString($reference['id']);
        self::assertSame(
            \sprintf('/api/forms/%s/files/%s', $id, $reference['id']),
            $this->client->getResponse()->headers->get('Location'),
        );

        // AND echoing it back verbatim is what makes the form hold it
        $this->putJson(\sprintf('/api/forms/%s/data', $id), json_encode(['invoice' => $reference], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));
        self::assertSame(['invoice' => $reference], $this->responseBody());
    }

    public function testAValuesDocumentCannotInventAFile(): void
    {
        // GIVEN a form, and a description of a file nobody uploaded
        $id = $this->createForm();
        $invented = ['id' => Uuid::v7()->toRfc4122(), 'name' => 'invoice.pdf', 'size' => 22, 'type' => 'application/pdf'];

        // WHEN
        $this->putJson(\sprintf('/api/forms/%s/data', $id), json_encode(['invoice' => $invented], \JSON_THROW_ON_ERROR));

        // THEN the one thing no schema could have said, said where it is wrong
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/invoice/id', $this->firstError()['pointer']);
        self::assertSame('form.file.unknown', $this->firstError()['code']);
    }

    public function testTheDescriptionHasToBeTheOneTheUploadAnsweredWith(): void
    {
        // GIVEN an uploaded file
        $id = $this->createForm();
        $this->upload($id, 'invoice.pdf', '%PDF-1.4 a tiny invoice');
        $reference = $this->responseBody();

        // WHEN a client sends back a different story about it
        $this->putJson(
            \sprintf('/api/forms/%s/data', $id),
            json_encode(['invoice' => [...$reference, 'name' => 'not-what-was-sent.pdf']], \JSON_THROW_ON_ERROR),
        );

        // THEN the finding points at the member that differs — which is what
        // makes `maxSize` and `accept` true rather than decorative
        self::assertResponseStatusCodeSame(422);
        self::assertSame('/invoice/name', $this->firstError()['pointer']);
        self::assertSame('form.file.mismatch', $this->firstError()['code']);
    }

    public function testAnUploadNobodyKeptCanBeThrownAwayAtOnce(): void
    {
        // GIVEN a form and a file somebody picked by mistake
        $id = $this->createForm();
        $this->upload($id, 'wrong.pdf', '%PDF-1.4 wrong file');
        $file = $this->responseBody()['id'];
        self::assertIsString($file);

        // WHEN
        $this->client->request('DELETE', \sprintf('/api/forms/%s/files/%s', $id, $file));

        // THEN it is gone, and it stays gone
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', \sprintf('/api/forms/%s/files/%s', $id, $file));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('urn:problem:ingot-forms:file-not-found', $this->responseBody()['type']);
    }

    public function testAFileTheStoredValuesNameCannotBeThrownAway(): void
    {
        // GIVEN a form that has saved the file
        $id = $this->createForm();
        $this->upload($id, 'invoice.pdf', '%PDF-1.4 a tiny invoice');
        $reference = $this->responseBody();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), json_encode(['invoice' => $reference], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        // WHEN
        $file = $reference['id'];
        self::assertIsString($file);
        $this->client->request('DELETE', \sprintf('/api/forms/%s/files/%s', $id, $file));

        // THEN a saved document is what makes a file permanent, and this is not
        // how it stops being permanent
        self::assertResponseStatusCodeSame(409);
        self::assertSame('urn:problem:ingot-forms:file-attached', $this->responseBody()['type']);
    }

    public function testAPartWithNoBytesInItIsNotAnUpload(): void
    {
        // GIVEN / WHEN
        $this->upload($this->createForm(), 'empty.pdf', '');

        // THEN
        self::assertResponseStatusCodeSame(422);
        self::assertSame('urn:problem:ingot-forms:upload-empty', $this->responseBody()['type']);
    }

    public function testMoreBytesThanTheDeploymentAcceptsAreRefused(): void
    {
        // GIVEN a file over what this environment allows (4 KiB, see the test
        // configuration — production's ceiling is FILES_MAX_UPLOAD)
        // WHEN
        $this->upload($this->createForm(), 'big.pdf', str_repeat('x', 5000));

        // THEN
        self::assertResponseStatusCodeSame(413);
        self::assertSame('urn:problem:ingot-forms:upload-too-large', $this->responseBody()['type']);
    }

    public function testAPartPhpCouldNotTakeWholeIsSaidToBeTooLarge(): void
    {
        // GIVEN a part PHP marked as over its own limit — the case where there is
        // nothing on disk to measure
        $id = $this->createForm();
        $path = $this->temporaryFile('%PDF-1.4 truncated');

        // WHEN
        $this->client->request(
            'POST',
            \sprintf('/api/forms/%s/files', $id),
            server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=----ingot'],
            files: ['file' => new UploadedFile($path, 'big.pdf', 'application/pdf', \UPLOAD_ERR_INI_SIZE, true)],
        );

        // THEN the honest answer, rather than "there was no file"
        self::assertResponseStatusCodeSame(413);
        self::assertSame('urn:problem:ingot-forms:upload-too-large', $this->responseBody()['type']);
    }

    public function testARequestWithNoFileInItIsRefused(): void
    {
        // GIVEN / WHEN a POST that forgot the part
        $this->client->request('POST', \sprintf('/api/forms/%s/files', $this->createForm()));

        // THEN
        self::assertResponseStatusCodeSame(422);
    }

    public function testAConfirmedFormTakesNoMoreBytes(): void
    {
        // GIVEN a form that is closed for good
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{}');
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));
        self::assertResponseStatusCodeSame(204);

        // WHEN
        $this->upload($id, 'late.pdf', '%PDF-1.4 too late');

        // THEN its values can never change again, so these bytes could never be
        // named
        self::assertResponseStatusCodeSame(409);
        self::assertSame('urn:problem:ingot-forms:form-locked', $this->responseBody()['type']);
    }

    public function testThereIsNoUploadingToAFormThatIsNotThere(): void
    {
        // GIVEN / WHEN
        $this->upload(Uuid::v7()->toRfc4122(), 'invoice.pdf', '%PDF-1.4 x');

        // THEN
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnExpiredFormAnswersGoneToBothEndpoints(): void
    {
        // GIVEN a form already past its date (planted directly — the API refuses
        // to create one)
        $id = FormId::next();
        $this->used[] = $id;
        $repository = self::getContainer()->get(DoctrineFormRepository::class);
        self::assertInstanceOf(DoctrineFormRepository::class, $repository);
        $repository->add(new Form($id, $this->definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));

        // WHEN / THEN
        $this->upload((string) $id, 'invoice.pdf', '%PDF-1.4 x');
        self::assertResponseStatusCodeSame(410);

        $this->client->request('DELETE', \sprintf('/api/forms/%s/files/%s', $id, Uuid::v7()->toRfc4122()));
        self::assertResponseStatusCodeSame(410);
    }

    private function createForm(): string
    {
        $payload = json_encode([
            'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            'definition' => self::DEFINITION,
        ], \JSON_THROW_ON_ERROR);

        $this->putJson('/api/forms', $payload, method: 'POST');
        self::assertResponseStatusCodeSame(201);

        $id = $this->responseBody()['id'] ?? '';
        self::assertIsString($id);
        $this->used[] = FormId::fromString($id);

        return $id;
    }

    private function definition(): Definition
    {
        $processor = self::getContainer()->get(FormDefinitionProcessor::class);
        self::assertInstanceOf(FormDefinitionProcessor::class, $processor);

        return $processor->document($processor->parse(self::DEFINITION));
    }

    private function upload(string $form, string $name, string $bytes): void
    {
        $this->client->request(
            'POST',
            \sprintf('/api/forms/%s/files', $form),
            server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=----ingot'],
            files: ['file' => new UploadedFile($this->temporaryFile($bytes), $name, null, null, true)],
        );
    }

    private function temporaryFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingot-api');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return $path;
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

    /**
     * @return array<string, mixed>
     */
    private function firstError(): array
    {
        $errors = $this->responseBody()['errors'] ?? [];
        self::assertIsArray($errors);
        $first = $errors[0] ?? [];
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }
}
