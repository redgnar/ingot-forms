<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * The archival copy of a confirmed form, over HTTP.
 *
 * What the record *says* is pinned where it is read
 * ({@see \App\Tests\Application\Forms\Record\FormRecordsTest}); what is pinned
 * here is the exchange — who may ask, what comes back, and what is refused. The
 * document itself is asserted as a document rather than as text: laying out a
 * PDF is a library's business, and a test that reads glyphs back out of one is
 * testing the library.
 */
final class FormRecordApiTest extends WebTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = ['items' => [
        ['type' => 'text', 'name' => 'title', 'required' => true, 'maxLength' => 80],
        ['type' => 'multiselect', 'name' => 'tags', 'options' => ['urgent', 'legal'], 'min' => 1],
    ]];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAConfirmedFormComesBackAsADocumentToKeep(): void
    {
        // GIVEN a confirmed form
        $id = $this->confirmed();

        // WHEN its record is asked for
        $this->client->request('GET', \sprintf('/api/manage/forms/%s/pdf', $id));
        $response = $this->client->getResponse();

        // THEN it is a PDF, and one that says it is meant to be kept rather than
        // shown: an attachment, and a browser is not to guess what it is
        self::assertResponseStatusCodeSame(200);
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString(\sprintf('attachment; filename=record-%s.pdf', $id), (string) $response->headers->get('Content-Disposition'));

        // AND it is a whole document: the header a reader looks for, an object
        // tree, and the end marker — a truncated render would have neither
        $document = (string) $response->getContent();
        self::assertStringStartsWith('%PDF-1.', $document);
        self::assertStringContainsString('/Type /Page', $document);
        self::assertStringEndsWith("%%EOF\n", $document);
    }

    public function testTheSameFormIsReadInWhicheverLanguageIsAskedFor(): void
    {
        // GIVEN a form whose presentation carries two catalogues
        $id = $this->confirmed(presented: true);

        // WHEN the record is asked for in each
        $documents = [];

        foreach (['en', 'pl', 'auto'] as $language) {
            $this->client->request('GET', \sprintf('/api/manage/forms/%s/pdf?lang=%s', $id, $language));
            self::assertResponseStatusCodeSame(200);
            $documents[$language] = (string) $this->client->getResponse()->getContent();
        }

        // THEN each is a document, and the two languages are not the same one —
        // a record read in Polish is a different document from the same record
        // read in English
        self::assertNotSame($documents['en'], $documents['pl']);

        // AND `auto` is the document's own default, which this one says is `en`
        // — so it is the English record rather than a third thing
        self::assertSame(\strlen($documents['en']), \strlen($documents['auto']));
    }

    public function testASignatureIsDrawnIntoTheRecordAndNotOnlyNamed(): void
    {
        // GIVEN a confirmed form answered with an image
        $id = $this->plant(presented: true, withFile: true);
        $file = $this->uploaded($id);
        $this->putJson(
            \sprintf('/api/forms/%s/data', $id),
            \sprintf('{"title": "A printer is broken", "tags": ["urgent"], "signature": %s}', $file),
        );
        self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));
        self::assertResponseStatusCodeSame(204);

        // WHEN the record is asked for
        $this->client->request('GET', \sprintf('/api/manage/forms/%s/pdf', $id));

        // THEN the bytes are in the document. A signature *is* an image, so a
        // record naming the file has described the answer rather than shown it
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('/Image', (string) $this->client->getResponse()->getContent());
    }

    public function testADraftIsNotARecordOfAnything(): void
    {
        // GIVEN a form somebody is still filling in
        $id = $this->plant();
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"title": "halfway"}');

        // WHEN a record is asked for
        $this->client->request('GET', \sprintf('/api/manage/forms/%s/pdf', $id));

        // THEN it is refused: the answers may still change, and a document
        // saying otherwise is one somebody could file
        self::assertResponseStatusCodeSame(409);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('urn:problem:ingot-forms:form-not-confirmed', $this->body()['type'] ?? null);
    }

    public function testAFormNobodyDescribedStillHasARecord(): void
    {
        // GIVEN a form created with no presentation at all — which a page cannot
        // be drawn for, and which is the normal shape of an API-only deployment
        $id = $this->confirmed(presented: false);

        // WHEN / THEN the record is there anyway: the definition is what was
        // asked, so it is what the record says
        $this->client->request('GET', \sprintf('/api/manage/forms/%s/pdf', $id));
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('%PDF-1.', (string) $this->client->getResponse()->getContent());
    }

    public function testWhatIsRefusedBeforeAnythingIsRendered(): void
    {
        // GIVEN a confirmed form
        $id = $this->confirmed();

        // WHEN a language nobody could have meant is asked for
        $this->client->request('GET', \sprintf('/api/manage/forms/%s/pdf?lang=%s', $id, 'polish!'));

        // THEN that is a bad request rather than a missing page, which is what
        // Symfony would have answered on its own
        self::assertResponseStatusCodeSame(422);
        $errors = $this->body()['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0] ?? null);
        self::assertSame('/lang', $errors[0]['pointer'] ?? null);

        // AND an unknown form is a missing one, whatever else is asked
        $this->client->request('GET', \sprintf('/api/manage/forms/%s/pdf', Uuid::v7()->toRfc4122()));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * A PNG through the API, exactly as a page would send one — and the answer
     * is what the values document may hold, verbatim.
     */
    private function uploaded(string $id): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sig') . '.png';
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        file_put_contents($path, $png === false ? '' : $png);

        $this->client->request(
            'POST',
            \sprintf('/api/forms/%s/files', $id),
            files: ['file' => new UploadedFile($path, 'signature.png', 'image/png', null, true)],
        );
        self::assertResponseStatusCodeSame(201);
        unlink($path);

        return (string) $this->client->getResponse()->getContent();
    }

    private function confirmed(bool $presented = true): string
    {
        $id = $this->plant($presented);
        $this->putJson(\sprintf('/api/forms/%s/data', $id), '{"title": "A printer is broken", "tags": ["urgent"]}');
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id));
        self::assertResponseStatusCodeSame(204);

        return $id;
    }

    private function plant(bool $presented = true, bool $withFile = false): string
    {
        $definition = self::DEFINITION;

        if ($withFile) {
            $definition['items'][] = ['type' => 'file', 'name' => 'signature', 'accept' => ['image/png'], 'maxSize' => 4096];
        }

        $payload = [
            'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
            'definition' => $definition,
        ];

        if ($presented) {
            $payload['presentation'] = [
                'engine' => 'core-html',
                'defaultLocale' => 'en',
                'items' => [
                    ['name' => 'title', 'widget' => 'text', 'label' => 'r.title'],
                    ['name' => 'tags', 'widget' => 'checkboxes', 'label' => 'r.tags',
                        'choices' => ['urgent' => 'r.urgent', 'legal' => 'r.legal']],
                    // A plain picker rather than a pad: a record draws an image
                    // because it is one, and never because of which widget asked
                    // for it — this document is written for the kit that has no
                    // pad at all.
                    ...($withFile ? [['name' => 'signature', 'widget' => 'file', 'label' => 'r.sig']] : []),
                    ['widget' => 'confirm', 'label' => 'r.send'],
                ],
                'translations' => [
                    'en' => ['r.title' => 'What happened', 'r.tags' => 'Tags', 'r.urgent' => 'Urgent', 'r.legal' => 'Legal', 'r.sig' => 'Your signature', 'r.send' => 'Send'],
                    'pl' => ['r.title' => 'Co się stało', 'r.tags' => 'Etykiety', 'r.urgent' => 'Pilne', 'r.legal' => 'Prawne', 'r.send' => 'Wyślij'],
                ],
            ];
        }

        $this->putJson('/api/manage/forms', json_encode($payload, \JSON_THROW_ON_ERROR), method: 'POST');
        self::assertResponseStatusCodeSame(201);

        $id = $this->body()['id'] ?? '';
        self::assertIsString($id);

        return $id;
    }

    private function putJson(string $url, string $content, string $method = 'PUT'): void
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: $content);
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
