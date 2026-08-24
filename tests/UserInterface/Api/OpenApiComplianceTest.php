<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use App\Infrastructure\Persistence\FormRecord;
use Doctrine\ORM\EntityManagerInterface;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\RequestValidator;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract tests against the generated documentation (`docs/openapi.yaml`,
 * produced by `make docs` — request schemas in it come from the request DTOs).
 *
 * Both halves of every exchange are checked: the request must match the
 * operation it targets (or, for scenarios that deliberately break the
 * contract, must be rejected by it), and the response must match the
 * documented status. A third test closes the loop the other way: every
 * documented operation + status needs a scenario, so the document cannot grow
 * a response nobody produces.
 *
 * Shape only — FormApiTest owns the behavioral assertions.
 */
final class OpenApiComplianceTest extends WebTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = [
        'items' => [
            ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 120],
            ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de', 'fr'], 'required' => true],
            ['type' => 'number', 'name' => 'age', 'min' => 18, 'max' => 120],
        ],
    ];

    private const string PRESENTATION = '{
        "engine": "core-html",
        "defaultLocale": "en",
        "items": [
            {"widget": "fieldset", "label": "contact.personal", "items": [
                {"name": "email", "widget": "text", "label": "contact.email"},
                {"name": "country", "widget": "radio", "label": "contact.country"},
                {"name": "age", "widget": "number", "label": "contact.age"}
            ]},
            {"widget": "paragraph", "label": "contact.note"},
            {"widget": "save", "label": "contact.save", "options": {"appearance": "link"}},
            {"widget": "confirm", "label": "contact.send"}
        ],
        "translations": {
            "en": {
                "contact.personal": "Personal details",
                "contact.email": "E-mail",
                "contact.country": "Country",
                "contact.age": "Age",
                "contact.note": "We reply within two days",
                "contact.save": "Save for later",
                "contact.send": "Send"
            }
        }
    }';

    private const string PARTIAL_DATA = '{"age": 36}';
    private const string COMPLETE_DATA = '{"email": "ada@example.com", "country": "pl", "age": 36}';

    private static ?RequestValidator $requestValidator = null;

    private static ?ResponseValidator $responseValidator = null;

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

    /**
     * @param bool                 $requestIsValid whether the staged request itself matches the contract
     * @param \Closure(self): void $stage
     */
    #[DataProvider('scenarios')]
    public function testExchangeMatchesTheDocumentedOperation(string $method, string $path, int $status, bool $requestIsValid, \Closure $stage): void
    {
        // GIVEN a request staged to produce this documented response
        $stage($this);
        $response = $this->client->getResponse();

        // THEN the scenario produced the status it claims to cover
        self::assertSame($status, $response->getStatusCode(), 'The scenario no longer produces the status it documents.');

        $factory = new Psr17Factory();
        $bridge = new PsrHttpFactory($factory, $factory, $factory, $factory);
        $psrRequest = $bridge->createRequest($this->client->getRequest());

        // AND the request either matches the contract or is refused by it
        if ($requestIsValid) {
            $this->assertRequestMatchesContract($psrRequest);
        } else {
            $this->assertContractRefuses($psrRequest);
        }

        // AND the response matches the operation it came from
        $psrResponse = $bridge->createResponse($response);
        $address = new OperationAddress($path, strtolower($method));

        try {
            self::responseValidator()->validate($address, $psrResponse);
        } catch (ValidationFailed $exception) {
            self::fail(\sprintf(
                "Response does not match docs/openapi.yaml: %s\nBody: %s",
                self::messageChain($exception),
                (string) $response->getContent(),
            ));
        }

        $this->addToAssertionCount(1);
    }

    public function testEveryDocumentedResponseIsExercised(): void
    {
        // GIVEN every operation + status pair the generated contract documents
        $spec = Yaml::parseFile(self::specFile());
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);
        $documented = [];

        foreach ($spec['paths'] as $path => $pathItem) {
            self::assertIsArray($pathItem);

            foreach ($pathItem as $method => $operation) {
                if (!\is_array($operation) || !isset($operation['responses'])) {
                    continue; // a path-level "parameters" entry, not an operation
                }

                self::assertIsArray($operation['responses']);

                foreach (array_keys($operation['responses']) as $status) {
                    $documented[] = self::label(strtoupper((string) $method), (string) $path, (int) $status);
                }
            }
        }

        // WHEN collecting what the scenarios above actually exercise
        $exercised = [];

        foreach (self::scenarios() as [$method, $path, $status]) {
            $exercised[] = self::label($method, $path, $status);
        }

        // THEN the two sets are identical — a documented response nobody
        // triggers, or a scenario aimed at an undocumented one, fails here
        $documented = array_values(array_unique($documented));
        $exercised = array_values(array_unique($exercised));
        sort($documented);
        sort($exercised);
        self::assertSame($documented, $exercised);
    }

    /**
     * Scenario keys carry the covered operation + status, plus a variant name
     * where one response has several routes to it.
     *
     * @return \Generator<string, array{string, string, int, bool, \Closure(self): void}>
     */
    public static function scenarios(): \Generator
    {
        $cases = [
            ['POST', '/api/forms', 201, true, '', static function (self $test): void {
                $test->postJson('/api/forms', self::createPayload());
            }],
            ['POST', '/api/forms', 400, false, '', static function (self $test): void {
                $test->postJson('/api/forms', '{broken');
            }],
            ['POST', '/api/forms', 415, false, '', static function (self $test): void {
                // Only JSON bodies are accepted, and the contract says so
                $test->client->request('POST', '/api/forms', server: ['CONTENT_TYPE' => 'text/plain'], content: self::createPayload());
            }],
            ['POST', '/api/forms', 422, true, 'expired', static function (self $test): void {
                // A past date is a valid date-time — only the app can know it is too late
                $test->postJson('/api/forms', self::createPayload(new \DateTimeImmutable('-1 hour')));
            }],
            ['POST', '/api/forms', 422, true, 'presentation does not fit', static function (self $test): void {
                $test->postJson('/api/forms', self::createPayload(presentation: '{"engine": "core-html", "items": [{"name": "nickname"}, {"widget": "confirm"}]}'));
            }],
            ['POST', '/api/forms', 422, false, 'unknown key', static function (self $test): void {
                // The request DTO closes the body, and so does the published schema
                $test->postJson('/api/forms', '{"expireDate": "2999-01-01T00:00:00+00:00", "definition": {}, "bogus": 1}');
            }],
            ['GET', '/api/forms/{id}', 200, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s', $test->createForm()));
            }],
            ['GET', '/api/forms/{id}', 404, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s', Uuid::v7()->toRfc4122()));
            }],
            ['GET', '/api/forms/{id}', 409, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s', $test->unreadableForm()));
            }],
            ['GET', '/api/forms/{id}', 410, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s', $test->expiredForm()));
            }],
            ['DELETE', '/api/forms/{id}', 204, true, '', static function (self $test): void {
                $test->client->request('DELETE', \sprintf('/api/forms/%s', $test->createForm()));
            }],
            ['DELETE', '/api/forms/{id}', 404, true, '', static function (self $test): void {
                $test->client->request('DELETE', \sprintf('/api/forms/%s', Uuid::v7()->toRfc4122()));
            }],
            ['DELETE', '/api/forms/{id}', 410, true, '', static function (self $test): void {
                $test->client->request('DELETE', \sprintf('/api/forms/%s', $test->expiredForm()));
            }],
            ['GET', '/api/forms/{id}/schema', 200, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/schema?mode=draft', $test->createForm()));
            }],
            ['GET', '/api/forms/{id}/schema', 404, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/schema', Uuid::v7()->toRfc4122()));
            }],
            ['GET', '/api/forms/{id}/schema', 410, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/schema', $test->expiredForm()));
            }],
            ['GET', '/api/forms/{id}/schema', 422, false, '', static function (self $test): void {
                // "bogus" is outside the enum the DeriveMode enum published
                $test->client->request('GET', \sprintf('/api/forms/%s/schema?mode=bogus', $test->createForm()));
            }],
            ['GET', '/api/schemas/{document}', 200, true, '', static function (self $test): void {
                $test->client->request('GET', '/api/schemas/definition');
            }],
            ['GET', '/api/schemas/{document}', 404, false, '', static function (self $test): void {
                // "values" is outside the enum this path publishes: a form's own
                // values schema is served from that form's address, not here.
                $test->client->request('GET', '/api/schemas/values');
            }],
            ['PUT', '/api/forms/{id}/data', 204, true, '', static function (self $test): void {
                $test->putJson(\sprintf('/api/forms/%s/data', $test->createForm()), self::PARTIAL_DATA);
            }],
            ['PUT', '/api/forms/{id}/data', 400, false, '', static function (self $test): void {
                $test->putJson(\sprintf('/api/forms/%s/data', $test->createForm()), '{broken');
            }],
            ['PUT', '/api/forms/{id}/data', 404, true, '', static function (self $test): void {
                $test->putJson(\sprintf('/api/forms/%s/data', Uuid::v7()->toRfc4122()), self::PARTIAL_DATA);
            }],
            ['PUT', '/api/forms/{id}/data', 409, true, '', static function (self $test): void {
                $test->putJson(\sprintf('/api/forms/%s/data', $test->confirmedForm()), self::PARTIAL_DATA);
            }],
            ['PUT', '/api/forms/{id}/data', 415, false, '', static function (self $test): void {
                $test->client->request('PUT', \sprintf('/api/forms/%s/data', $test->createForm()), server: ['CONTENT_TYPE' => 'text/plain'], content: self::PARTIAL_DATA);
            }],
            ['PUT', '/api/forms/{id}/data', 410, true, '', static function (self $test): void {
                $test->putJson(\sprintf('/api/forms/%s/data', $test->expiredForm()), self::PARTIAL_DATA);
            }],
            ['PUT', '/api/forms/{id}/data', 422, true, '', static function (self $test): void {
                // Valid JSON values — the per-form derived schema is what rejects them
                $test->putJson(\sprintf('/api/forms/%s/data', $test->createForm()), '{"age": "old"}');
            }],
            ['GET', '/api/forms/{id}/data', 200, true, '', static function (self $test): void {
                $id = $test->createForm();
                $test->putJson(\sprintf('/api/forms/%s/data', $id), self::PARTIAL_DATA);
                $test->client->request('GET', \sprintf('/api/forms/%s/data', $id));
            }],
            ['GET', '/api/forms/{id}/data', 404, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/data', $test->createForm()));
            }],
            ['GET', '/api/forms/{id}/data', 410, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/data', $test->expiredForm()));
            }],
            ['POST', '/api/forms/{id}/confirm', 204, true, '', static function (self $test): void {
                $id = $test->createForm();
                $test->putJson(\sprintf('/api/forms/%s/data', $id), self::COMPLETE_DATA);
                $test->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));
            }],
            ['POST', '/api/forms/{id}/confirm', 404, true, '', static function (self $test): void {
                $test->client->request('POST', \sprintf('/api/forms/%s/confirm', Uuid::v7()->toRfc4122()));
            }],
            ['POST', '/api/forms/{id}/confirm', 409, true, '', static function (self $test): void {
                $test->client->request('POST', \sprintf('/api/forms/%s/confirm', $test->createForm()));
            }],
            ['POST', '/api/forms/{id}/confirm', 410, true, '', static function (self $test): void {
                $test->client->request('POST', \sprintf('/api/forms/%s/confirm', $test->expiredForm()));
            }],
            ['POST', '/api/forms/{id}/confirm', 422, true, '', static function (self $test): void {
                $id = $test->createForm();
                $test->putJson(\sprintf('/api/forms/%s/data', $id), self::PARTIAL_DATA);
                $test->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));
            }],
            ['GET', '/api/forms/{id}/presentation', 200, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/presentation', $test->presentedForm()));
            }],
            ['GET', '/api/forms/{id}/presentation', 404, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/presentation', $test->createForm()));
            }],
            ['GET', '/api/forms/{id}/presentation', 410, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/presentation', $test->expiredForm()));
            }],
            ['GET', '/api/forms/{id}/history', 200, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/history', $test->savedForm()));
            }],
            ['GET', '/api/forms/{id}/history', 404, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/history', Uuid::v7()->toRfc4122()));
            }],
            ['GET', '/api/forms/{id}/history', 410, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/history', $test->expiredForm()));
            }],
            ['GET', '/api/forms/{id}/history/{seq}', 200, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/history/1', $test->savedForm()));
            }],
            ['GET', '/api/forms/{id}/history/{seq}', 404, true, '', static function (self $test): void {
                // The form exists and was never saved that many times
                $test->client->request('GET', \sprintf('/api/forms/%s/history/7', $test->savedForm()));
            }],
            ['GET', '/api/forms/{id}/history/{seq}', 410, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/history/1', $test->expiredForm()));
            }],
            ['POST', '/api/forms/{id}/files', 201, true, '', static function (self $test): void {
                $test->uploadTo($test->createForm());
            }],
            ['POST', '/api/forms/{id}/files', 404, true, '', static function (self $test): void {
                $test->uploadTo(Uuid::v7()->toRfc4122());
            }],
            ['POST', '/api/forms/{id}/files', 409, true, '', static function (self $test): void {
                $test->uploadTo($test->confirmedForm());
            }],
            ['POST', '/api/forms/{id}/files', 410, true, '', static function (self $test): void {
                $test->uploadTo($test->expiredForm());
            }],
            ['POST', '/api/forms/{id}/files', 413, true, '', static function (self $test): void {
                // A part PHP marked as over its own limit: a perfectly formed
                // request whose bytes never arrived
                $test->uploadTo($test->createForm(), \UPLOAD_ERR_INI_SIZE);
            }],
            ['POST', '/api/forms/{id}/files', 422, false, '', static function (self $test): void {
                // The contract requires the part, and so does the endpoint
                $test->client->request('POST', \sprintf('/api/forms/%s/files', $test->createForm()));
            }],
            ['DELETE', '/api/forms/{id}/files/{fileId}', 204, true, '', static function (self $test): void {
                $id = $test->createForm();
                $file = $test->uploadTo($id);
                $test->client->request('DELETE', \sprintf('/api/forms/%s/files/%s', $id, $file));
            }],
            ['DELETE', '/api/forms/{id}/files/{fileId}', 404, true, '', static function (self $test): void {
                $test->client->request('DELETE', \sprintf('/api/forms/%s/files/%s', $test->createForm(), Uuid::v7()->toRfc4122()));
            }],
            ['DELETE', '/api/forms/{id}/files/{fileId}', 409, true, '', static function (self $test): void {
                $test->client->request('DELETE', \sprintf('/api/forms/%s/files/%s', ...$test->formHoldingAFile()));
            }],
            ['GET', '/api/forms/{id}/files/{fileId}', 200, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/files/%s', ...$test->formHoldingAFile()));
            }],
            ['GET', '/api/forms/{id}/files/{fileId}', 404, true, '', static function (self $test): void {
                // Uploaded, never saved: unreachable, and indistinguishable from
                // a file that never existed
                $id = $test->createForm();
                $test->client->request('GET', \sprintf('/api/forms/%s/files/%s', $id, $test->uploadTo($id)));
            }],
            ['GET', '/api/forms/{id}/files/{fileId}', 410, true, '', static function (self $test): void {
                $test->client->request('GET', \sprintf('/api/forms/%s/files/%s', $test->expiredForm(), Uuid::v7()->toRfc4122()));
            }],
            ['DELETE', '/api/forms/{id}/files/{fileId}', 410, true, '', static function (self $test): void {
                $test->client->request('DELETE', \sprintf('/api/forms/%s/files/%s', $test->expiredForm(), Uuid::v7()->toRfc4122()));
            }],
        ];

        foreach ($cases as [$method, $path, $status, $requestIsValid, $variant, $stage]) {
            $key = self::label($method, $path, $status) . ($variant === '' ? '' : ' (' . $variant . ')');

            yield $key => [$method, $path, $status, $requestIsValid, $stage];
        }
    }

    private function assertRequestMatchesContract(ServerRequestInterface $request): void
    {
        try {
            self::requestValidator()->validate($request);
        } catch (ValidationFailed $exception) {
            self::fail(\sprintf('Request does not match docs/openapi.yaml: %s', self::messageChain($exception)));
        }

        $this->addToAssertionCount(1);
    }

    private function assertContractRefuses(ServerRequestInterface $request): void
    {
        try {
            self::requestValidator()->validate($request);
        } catch (ValidationFailed) {
            // As intended: the scenario sends what the contract forbids
            $this->addToAssertionCount(1);

            return;
        }

        self::fail('The contract accepts a request this scenario sends to be rejected — it is looser than the implementation.');
    }

    private static function requestValidator(): RequestValidator
    {
        return self::$requestValidator ??= self::builder()->getRequestValidator();
    }

    private static function responseValidator(): ResponseValidator
    {
        return self::$responseValidator ??= self::builder()->getResponseValidator();
    }

    private static function builder(): ValidatorBuilder
    {
        return new ValidatorBuilder()->fromYamlFile(self::specFile());
    }

    private static function specFile(): string
    {
        $file = \dirname(__DIR__, 3) . '/docs/openapi.yaml';
        self::assertFileExists($file, 'The generated contract is missing — run `make docs`.');

        return $file;
    }

    private static function label(string $method, string $path, int $status): string
    {
        return \sprintf('%s %s → %d', $method, $path, $status);
    }

    private static function createPayload(?\DateTimeImmutable $expireDate = null, ?string $presentation = null): string
    {
        $payload = [
            'expireDate' => ($expireDate ?? new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM),
            'definition' => self::DEFINITION,
        ];

        if ($presentation !== null) {
            $payload['presentation'] = json_decode($presentation, true, flags: \JSON_THROW_ON_ERROR);
        }

        return json_encode($payload, \JSON_THROW_ON_ERROR);
    }

    private static function messageChain(\Throwable $throwable): string
    {
        $messages = [];

        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return implode(' ← ', $messages);
    }

    /**
     * Uploads a part named `file` and answers with the id the store minted — or
     * whatever the response was, when the scenario is one that fails.
     */
    private function uploadTo(string $form, ?int $error = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ingot-contract');
        self::assertIsString($path);
        file_put_contents($path, '%PDF-1.4 a tiny invoice');
        $this->temporary[] = $path;

        $this->client->request(
            'POST',
            \sprintf('/api/forms/%s/files', $form),
            // The header a browser would send: BrowserKit hands the parts over
            // directly, but the contract is checked against what the request says
            // it is.
            server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=----ingot'],
            files: ['file' => new UploadedFile($path, 'invoice.pdf', 'application/pdf', $error, true)],
        );

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($body) && \is_string($body['id'] ?? null) ? $body['id'] : '';
    }

    /**
     * A form whose stored values name a file — the one state in which the file
     * cannot be thrown away.
     *
     * @return array{string, string}
     */
    private function formHoldingAFile(): array
    {
        $this->postJson('/api/forms', json_encode([
            'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            'definition' => ['items' => [['type' => 'file', 'name' => 'invoice', 'accept' => ['application/pdf'], 'maxSize' => 4096]]],
        ], \JSON_THROW_ON_ERROR));
        $created = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertIsString($created['id'] ?? null);
        $form = $created['id'];

        $this->uploadTo($form);
        $reference = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($reference);
        self::assertIsString($reference['id'] ?? null);

        $this->putJson(\sprintf('/api/forms/%s/data', $form), json_encode(['invoice' => $reference], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        return [$form, $reference['id']];
    }

    /** A form that has saved something, so it has a history to read. */
    private function savedForm(): string
    {
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), self::PARTIAL_DATA);
        self::assertResponseStatusCodeSame(204);

        return $id;
    }

    private function createForm(): string
    {
        $this->postJson('/api/forms', self::createPayload());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id'] ?? null);

        return $body['id'];
    }

    /**
     * A row that today's rules refuse to read: written straight through Doctrine,
     * because the API would no longer accept it.
     */
    private function unreadableForm(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $record = new FormRecord();
        $record->id = Uuid::fromString($id);
        $record->definition = json_encode(self::DEFINITION, \JSON_THROW_ON_ERROR);
        $record->expireDate = new \DateTimeImmutable('+1 day');
        $record->createdAt = new \DateTimeImmutable();
        $record->presentation = '{"engine":"core-html","items":[{"name":"email","widget":"text"}]}';
        $entityManager->persist($record);
        $entityManager->flush();

        return $id;
    }

    private function presentedForm(): string
    {
        $this->postJson('/api/forms', self::createPayload(presentation: self::PRESENTATION));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id'] ?? null);

        return $body['id'];
    }

    private function confirmedForm(): string
    {
        $id = $this->createForm();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), self::COMPLETE_DATA);
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));
        self::assertResponseStatusCodeSame(204);

        return $id;
    }

    private function expiredForm(): string
    {
        $id = Uuid::v7()->toRfc4122();
        $repository = self::getContainer()->get(DoctrineFormRepository::class);
        self::assertInstanceOf(DoctrineFormRepository::class, $repository);
        $repository->add(new Form(FormId::fromString($id), self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));

        return $id;
    }

    /** What a form planted straight into storage is made of. */
    private static function definition(): Definition
    {
        return Definition::stored(
            json_encode(self::DEFINITION, \JSON_THROW_ON_ERROR),
            new FormDefinitionProcessor(new FormMapperFactory()->create()),
        );
    }

    private function postJson(string $url, string $content): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: $content);
    }

    private function putJson(string $url, string $content): void
    {
        $this->client->request('PUT', $url, server: ['CONTENT_TYPE' => 'application/json'], content: $content);
    }
}
