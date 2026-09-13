<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use App\Infrastructure\Persistence\FormRecord;
use App\Infrastructure\Persistence\FormTemplateRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The catalogue over HTTP.
 *
 * Three sentences carry this whole surface and each has a case here: **a
 * template is born usable**, **publishing is never activating**, and **the pair
 * is stated whole**.
 */
final class FormTemplateApiTest extends WebTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = ['items' => [['type' => 'text', 'name' => 'email', 'required' => true]]];

    /** @var array<string, mixed> */
    private const array OTHER_DEFINITION = ['items' => [['type' => 'text', 'name' => 'nickname']]];

    /** @var array<string, mixed> */
    private const array SHOWS_EMAIL = [
        'engine' => 'core-html',
        'items' => [['name' => 'email', 'widget' => 'text'], ['widget' => 'confirm']],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testATemplateIsBornUsableAndSaysWhatItHolds(): void
    {
        // GIVEN / WHEN a template is created
        $id = $this->createTemplate();

        // THEN the answer is the id and the two numbers, and nothing the client
        // already sent
        self::assertSame(['id' => $id, 'definition' => 1, 'presentation' => 1], $this->body());
        self::assertSame(\sprintf('/api/manage/form-templates/%s', $id), $this->client->getResponse()->headers->get('Location'));

        // AND reading it back gives the pair in use as numbers, plus what a
        // delete would be refused over
        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Damage report', $this->member('name'));
        self::assertSame(1, $this->member('definition'));
        self::assertSame(1, $this->member('presentation'));
        self::assertSame(0, $this->member('forms'));
    }

    public function testTheCatalogueListsWhatIsInIt(): void
    {
        // GIVEN a template
        $id = $this->createTemplate();

        // WHEN the catalogue is read
        $this->client->request('GET', '/api/manage/form-templates');

        // THEN it is in there with the pair it uses
        $mine = array_values(array_filter(
            $this->rows('templates'),
            static fn(mixed $row): bool => \is_array($row) && ($row['id'] ?? null) === $id,
        ));
        self::assertCount(1, $mine);
        // No `assertIsArray` here: the filter above already established it, and
        // an assertion that cannot fail is one more line to read and no more
        // proof than the line before it.
        self::assertSame(1, $mine[0]['definition']);
    }

    public function testPublishingAddsToAHistoryAndChangesNothingInUse(): void
    {
        // GIVEN a template in use
        $id = $this->createTemplate();

        // WHEN a second definition is published
        $this->postJson(\sprintf('/api/manage/form-templates/%s/definitions', $id), ['definition' => self::OTHER_DEFINITION]);

        // THEN it is numbered 2
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame(['definition' => 2], $this->body());

        // AND what forms are made of has not moved
        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s', $id));
        self::assertSame(1, $this->member('definition'));

        // AND the history has both, newest first
        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s/definitions', $id));
        self::assertSame([2, 1], array_column($this->rows('definitions'), 'seq'));
    }

    public function testAPublishedDocumentComesBackByteForByte(): void
    {
        // GIVEN a template
        $id = $this->createTemplate();

        // WHEN version 1 of the definition is read
        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s/definitions/1', $id));

        // THEN it is the document itself, and it still says what was sent
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $items = $this->rows('items');
        self::assertIsArray($items[0]);
        self::assertSame('email', $items[0]['name']);
    }

    public function testMovingTheCurrentPairIsWhatChangesWhatFormsAreMadeOf(): void
    {
        // GIVEN a template with a second definition prepared
        $id = $this->createTemplate();
        $this->postJson(\sprintf('/api/manage/form-templates/%s/definitions', $id), ['definition' => self::OTHER_DEFINITION]);

        // WHEN the pair is stated whole, naming no presentation
        $this->putJson(\sprintf('/api/manage/form-templates/%s/current', $id), ['definition' => 2]);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        // THEN the definition moved *and* the template now shows nothing: an
        // omitted presentation means none, not the one it had
        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s', $id));
        self::assertSame(2, $this->member('definition'));
        self::assertNull($this->member('presentation'));
    }

    public function testAPairThatDoesNotFitIsRefusedAtThePointer(): void
    {
        // GIVEN a template showing an email, and a definition that stops asking
        // for one
        $id = $this->createTemplate();
        $this->postJson(\sprintf('/api/manage/form-templates/%s/definitions', $id), ['definition' => self::OTHER_DEFINITION]);

        // WHEN the two are named together
        $this->putJson(\sprintf('/api/manage/form-templates/%s/current', $id), ['definition' => 2, 'presentation' => 1]);

        // THEN it is refused with the findings pointing at the item, not at the
        // pair
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('presentation.item.unknown', $this->firstError()['code']);
    }

    public function testAPresentationIsJudgedWhenItIsPublished(): void
    {
        // GIVEN a template whose definition asks for an email
        $id = $this->createTemplate();

        // WHEN a presentation showing something else is published
        $this->postJson(\sprintf('/api/manage/form-templates/%s/presentations', $id), [
            'presentation' => ['engine' => 'core-html', 'items' => [['name' => 'nickname', 'widget' => 'text'], ['widget' => 'confirm']]],
        ]);

        // THEN it is refused here, where somebody can still fix it
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('presentation.item.unknown', $this->firstError()['code']);
    }

    public function testRenamingChangesTheLabelAndNothingElse(): void
    {
        // GIVEN
        $id = $this->createTemplate();

        // WHEN
        $this->putJson(\sprintf('/api/manage/form-templates/%s/name', $id), ['name' => 'Claim']);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        // THEN
        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s', $id));
        self::assertSame('Claim', $this->member('name'));
        self::assertSame(1, $this->member('definition'));
    }

    public function testABlankNameIsRefusedWithItsCode(): void
    {
        // GIVEN / WHEN
        $this->postJson('/api/manage/form-templates', ['name' => '   ', 'definition' => self::DEFINITION]);

        // THEN
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $error = $this->firstError();
        self::assertSame('/name', $error['pointer']);
        self::assertSame('template.name.blank', $error['code']);
    }

    public function testATemplateThatIsNotThereAnswers404(): void
    {
        // GIVEN / WHEN
        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s', Uuid::v7()->toRfc4122()));

        // THEN
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame('urn:problem:ingot-forms:form-template-not-found', $this->member('type'));
    }

    public function testANumberNobodyPublishedAnswers404(): void
    {
        // GIVEN a template with one version
        $id = $this->createTemplate();

        // WHEN a number it never handed out is read
        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s/definitions/9', $id));

        // THEN it is a missing document, not a bad request — the same answer as
        // for a revision number nobody saved
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame('urn:problem:ingot-forms:template-version-not-found', $this->member('type'));
    }

    public function testAMemberTheRequestDoesNotDeclareIsAClientBugWorthReporting(): void
    {
        // GIVEN / WHEN
        $this->postJson('/api/manage/form-templates', ['name' => 'Damage report', 'definition' => self::DEFINITION, 'bogus' => 1]);

        // THEN
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('request.unexpected_key', $this->firstError()['code']);
    }

    public function testATemplateNothingIsMadeOfCanBeDeleted(): void
    {
        // GIVEN a template nobody created a form from
        $id = $this->createTemplate();

        // WHEN
        $this->client->request('DELETE', \sprintf('/api/manage/form-templates/%s', $id));
        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        // THEN it is out of the catalogue
        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s', $id));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testATemplateFormsAreMadeOfIsRefusedUntilItIsEmptied(): void
    {
        // GIVEN a template and a form made from its definition
        $id = $this->createTemplate();
        $this->plantFormMadeFrom($id);

        // WHEN the template is deleted
        $this->client->request('DELETE', \sprintf('/api/manage/form-templates/%s', $id));

        // THEN it is refused, and the read says how much stands in the way
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('urn:problem:ingot-forms:template-in-use', $this->member('type'));

        $this->client->request('GET', \sprintf('/api/manage/form-templates/%s', $id));
        self::assertSame(1, $this->member('forms'));

        // WHEN it is emptied first
        $this->client->request('DELETE', \sprintf('/api/manage/form-templates/%s/forms', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->member('deleted'));
        self::assertSame(0, $this->member('remaining'));

        // THEN the delete goes through — emptying is the deliberate act that has
        // to come first, and never something a delete does on the way past
        $this->client->request('DELETE', \sprintf('/api/manage/form-templates/%s', $id));
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A form pointing at the definition this template has in use.
     *
     * Written straight through Doctrine, because nothing can create one through
     * the API yet: posting the same document creates a **new** one-off, which is
     * a different row and would prove the opposite of what this test is about.
     * Creating a form *from a template* is the next block; until it lands, this
     * is what a form made of a published version looks like.
     */
    private function plantFormMadeFrom(string $template): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $row = $entityManager->find(FormTemplateRecord::class, Uuid::fromString($template));
        self::assertInstanceOf(FormTemplateRecord::class, $row);

        $form = new FormRecord();
        $form->id = Uuid::v7();
        $form->identityMode = 'anonymous';
        $form->definitionId = $row->currentDefinitionId;
        $form->expireDate = new \DateTimeImmutable('+1 day');
        $form->createdAt = new \DateTimeImmutable();
        $entityManager->persist($form);
        $entityManager->flush();
    }

    private function createTemplate(): string
    {
        $this->postJson('/api/manage/form-templates', [
            'name' => 'Damage report',
            'definition' => self::DEFINITION,
            'presentation' => self::SHOWS_EMAIL,
        ]);
        $id = $this->member('id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $url, array $payload): void
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function putJson(string $url, array $payload): void
    {
        $this->client->request('PUT', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function body(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * One member of the answer, asserted to be there before it is read — the
     * alternative is a stack of `mixed` offsets that say nothing when they fail.
     */
    private function member(string $key): mixed
    {
        $body = $this->body();
        self::assertArrayHasKey($key, $body);

        return $body[$key];
    }

    /**
     * The first finding of a validation report, which is what every refusal here
     * is asserted by.
     *
     * @return array<array-key, mixed>
     */
    private function firstError(): array
    {
        $errors = $this->member('errors');
        self::assertIsArray($errors);
        self::assertIsArray($errors[0] ?? null);

        return $errors[0];
    }

    /**
     * @return list<mixed>
     */
    private function rows(string $key): array
    {
        $rows = $this->member($key);
        self::assertIsArray($rows);

        return array_values($rows);
    }
}
