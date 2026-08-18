<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Infrastructure\Persistence\FormRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class FormApiTest extends WebTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = [
        'id' => 'contact',
        'title' => 'Contact us',
        'fields' => [
            ['type' => 'text', 'name' => 'email', 'label' => 'E-mail', 'required' => true, 'maxLength' => 120],
            ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de', 'fr'], 'required' => true],
            ['type' => 'number', 'name' => 'age', 'min' => 18, 'max' => 120],
        ],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testFullLifecycleFromCreationToConfirmedLock(): void
    {
        // GIVEN a freshly created form
        $id = $this->createForm();
        $created = $this->responseBody();
        self::assertSame('empty', $created['status']);
        self::assertSame('Contact us', $created['title']);
        self::assertSame(\sprintf('/api/forms/%s', $id), $this->client->getResponse()->headers->get('Location'));

        // WHEN saving partial progress
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 36}');

        // THEN the draft is stored even though required fields are missing, and the
        // answer is the status alone — the client keeps no copy it did not ask for
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        $this->client->request('GET', \sprintf('/api/forms/%s', $id));
        self::assertSame('draft', $this->responseBody()['status']);

        // WHEN confirming the incomplete draft
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));

        // THEN confirmation enforces the strict contract
        self::assertResponseStatusCodeSame(422);
        self::assertSame('form.value.required', $this->firstError()['code']);

        // WHEN completing and confirming
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"email": "ada@example.com", "country": "pl", "age": 36}');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));

        // THEN the form is locked for good, again with no body
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        $this->client->request('GET', \sprintf('/api/forms/%s', $id));
        $confirmed = $this->responseBody();
        self::assertSame('confirmed', $confirmed['status']);
        self::assertNotNull($confirmed['confirmedAt']);

        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 37}');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('urn:problem:ingot-forms:form-locked', $this->responseBody()['type']);

        // AND the confirmed values read back unchanged (jsonb reorders keys)
        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));
        self::assertResponseStatusCodeSame(200);
        $data = $this->responseBody();
        ksort($data);
        self::assertSame(['age' => 36, 'country' => 'pl', 'email' => 'ada@example.com'], $data);
    }

    public function testMalformedRequestBodyIsA400Problem(): void
    {
        // GIVEN / WHEN
        $this->putJson('/api/forms', '{broken', method: 'POST');

        // THEN
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('urn:problem:ingot-forms:malformed-json', $this->responseBody()['type']);
    }

    public function testPastExpireDateIsRejected(): void
    {
        // GIVEN / WHEN
        $this->postForm(self::DEFINITION, new \DateTimeImmutable('-1 hour'));

        // THEN
        self::assertResponseStatusCodeSame(422);
        $error = $this->firstError();
        self::assertSame('/expireDate', $error['pointer']);
        self::assertSame('form.expire_date.past', $error['code']);
    }

    public function testOnlyJsonBodiesAreAccepted(): void
    {
        // GIVEN a perfectly good payload sent as a form
        $payload = json_encode([
            'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            'definition' => self::DEFINITION,
        ], \JSON_THROW_ON_ERROR);

        // WHEN
        $this->client->request('POST', '/api/forms', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: $payload);

        // THEN the media type is refused before anything is mapped
        self::assertResponseStatusCodeSame(415);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('urn:problem:ingot-forms:unsupported-media-type', $this->responseBody()['type']);
    }

    public function testMissingBodyMemberIsReportedAtItsPointer(): void
    {
        // GIVEN a body without the required expireDate
        $payload = json_encode(['definition' => self::DEFINITION], \JSON_THROW_ON_ERROR);

        // WHEN
        $this->putJson('/api/forms', $payload, method: 'POST');

        // THEN the DTO could not be built, and says so in the wire's terms
        self::assertResponseStatusCodeSame(422);
        $error = $this->firstError();
        self::assertSame('/expireDate', $error['pointer']);
        self::assertSame('request.type', $error['code']);
        self::assertSame('This member is missing or is not an RFC 3339 date-time.', $error['message']);
    }

    public function testUnknownBodyKeyIsRejectedByTheRequestDto(): void
    {
        // GIVEN a well-formed request carrying one member too many
        $payload = json_encode([
            'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            'definition' => self::DEFINITION,
            'bogus' => 1,
        ], \JSON_THROW_ON_ERROR);

        // WHEN
        $this->putJson('/api/forms', $payload, method: 'POST');

        // THEN the DTO's closed contract answers, pointing at the extra member
        self::assertResponseStatusCodeSame(422);
        $error = $this->firstError();
        self::assertSame('/bogus', $error['pointer']);
        self::assertSame('request.unexpected_key', $error['code']);
    }

    public function testDataPayloadMustBeAJsonObject(): void
    {
        // GIVEN a form and a body that is a list, not a set of field values
        $id = $this->createForm();

        // WHEN
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '[1, 2]');

        // THEN the request DTO answers before any per-form rule applies
        self::assertResponseStatusCodeSame(422);
        $error = $this->firstError();
        self::assertSame('', $error['pointer']);
        self::assertSame('request.type', $error['code']);
    }


    public function testUnknownSchemaModeIsRejected(): void
    {
        // GIVEN
        $id = $this->createForm();

        // WHEN
        $this->client->request('GET', \sprintf('/api/forms/%s/schema?mode=bogus', $id));

        // THEN
        self::assertResponseStatusCodeSame(422);
        $error = $this->firstError();
        self::assertSame('/mode', $error['pointer']);
        self::assertSame('request.choice', $error['code']);
    }

    public function testDefinitionErrorsAreReportedUnderTheDefinitionPointer(): void
    {
        // GIVEN a definition with duplicate field names
        $definition = self::DEFINITION;
        $definition['fields'] = [
            ['type' => 'text', 'name' => 'email'],
            ['type' => 'text', 'name' => 'email'],
        ];

        // WHEN
        $this->postForm($definition, new \DateTimeImmutable('+1 day'));

        // THEN the pointer is re-rooted to where the client sent the document
        self::assertResponseStatusCodeSame(422);
        $error = $this->firstError();
        self::assertSame('/definition/fields/1/name', $error['pointer']);
        self::assertSame('form.field.duplicate-name', $error['code']);
    }

    public function testUnknownFormIsA404Problem(): void
    {
        // GIVEN / WHEN
        $this->client->request('GET', \sprintf('/api/forms/%s', Uuid::v7()->toRfc4122()));

        // THEN
        self::assertResponseStatusCodeSame(404);
        self::assertSame('urn:problem:ingot-forms:form-not-found', $this->responseBody()['type']);
    }

    public function testExpiredFormAnswers410Everywhere(): void
    {
        // GIVEN a form already past its expire_date (planted directly — the
        // API refuses to create one)
        $id = Uuid::v7()->toRfc4122();
        $repository = self::getContainer()->get(FormRepository::class);
        self::assertInstanceOf(FormRepository::class, $repository);
        $repository->insert(Uuid::fromString($id), json_encode(self::DEFINITION, \JSON_THROW_ON_ERROR), new \DateTimeImmutable('-1 hour'));

        // WHEN / THEN reads and writes both report gone
        $this->client->request('GET', \sprintf('/api/forms/%s', $id));
        self::assertResponseStatusCodeSame(410);
        self::assertSame('urn:problem:ingot-forms:form-gone', $this->responseBody()['type']);

        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"age": 36}');
        self::assertResponseStatusCodeSame(410);
    }

    public function testSchemaEndpointServesStrictAndDraftVariants(): void
    {
        // GIVEN
        $id = $this->createForm();

        // WHEN reading the strict (default) schema
        $this->client->request('GET', \sprintf('/api/forms/%s/schema', $id));

        // THEN it is a schema document with the required contract
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/schema+json');
        $strict = $this->responseBody();
        self::assertSame(['email', 'country'], $strict['required']);

        // WHEN reading the draft variant
        $this->client->request('GET', \sprintf('/api/forms/%s/schema?mode=draft', $id));

        // THEN nothing is required, value contracts stay
        $draft = $this->responseBody();
        self::assertArrayNotHasKey('required', $draft);
        self::assertIsArray($draft['properties']);
        self::assertArrayHasKey('email', $draft['properties']);
    }

    public function testDeletedFormDisappears(): void
    {
        // GIVEN
        $id = $this->createForm();

        // WHEN
        $this->client->request('DELETE', \sprintf('/api/forms/%s', $id));

        // THEN
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', \sprintf('/api/forms/%s', $id));
        self::assertResponseStatusCodeSame(404);
    }

    public function testFormWithUnknownFieldTypeCanBeDraftedButNotConfirmed(): void
    {
        // GIVEN a definition containing a plugin field type
        $definition = self::DEFINITION;
        $definition['fields'][] = ['type' => 'signature', 'name' => 'sig', 'vendor' => ['pad' => '2.0']];
        $id = $this->postForm($definition, new \DateTimeImmutable('+1 day'));
        self::assertResponseStatusCodeSame(201);

        // WHEN drafting complete data — the unknown field accepts anything
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"email": "ada@example.com", "country": "pl", "sig": {"strokes": []}}');
        self::assertResponseStatusCodeSame(204);

        // THEN confirmation refuses to vouch for an unknown value contract
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));
        self::assertResponseStatusCodeSame(422);
        $error = $this->firstError();
        self::assertSame('form.data.unknown-field-type', $error['code']);
        self::assertSame('/fields/3/type', $error['pointer']);
    }

    public function testReadingDataOfAnEmptyFormIsA404Problem(): void
    {
        // GIVEN
        $id = $this->createForm();

        // WHEN
        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));

        // THEN
        self::assertResponseStatusCodeSame(404);
        self::assertSame('urn:problem:ingot-forms:form-data-empty', $this->responseBody()['type']);
    }

    public function testConfirmingAnEmptyFormIsA409Problem(): void
    {
        // GIVEN
        $id = $this->createForm();

        // WHEN
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));

        // THEN
        self::assertResponseStatusCodeSame(409);
        self::assertSame('urn:problem:ingot-forms:form-data-empty', $this->responseBody()['type']);
    }


    /**
     * @param array<string, mixed> $definition
     */
    private function postForm(array $definition, \DateTimeImmutable $expireDate): string
    {
        $payload = json_encode([
            'expireDate' => $expireDate->format(\DateTimeInterface::ATOM),
            'definition' => $definition,
        ], \JSON_THROW_ON_ERROR);

        $this->putJson('/api/forms', $payload, method: 'POST');

        $id = $this->responseBody()['id'] ?? '';

        return \is_string($id) ? $id : '';
    }

    private function createForm(): string
    {
        $id = $this->postForm(self::DEFINITION, new \DateTimeImmutable('+1 day'));
        self::assertResponseStatusCodeSame(201);

        return $id;
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
        $body = $this->responseBody();
        self::assertIsArray($body['errors']);
        self::assertIsArray($body['errors'][0]);

        /** @var array<string, mixed> */
        return $body['errors'][0];
    }
}
