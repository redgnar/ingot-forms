<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Web;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The page a person opens: which form it draws, in which language, and what it
 * answers when it cannot draw one.
 */
final class ViewFormActionTest extends WebTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = [
        'id' => 'contact',
        'items' => [
            ['type' => 'text', 'name' => 'email', 'required' => true],
            ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de']],
        ],
    ];

    /** @var array<string, mixed> */
    private const array PRESENTATION = [
        'engine' => 'core-html',
        'defaultLocale' => 'en',
        'items' => [
            ['name' => 'email', 'widget' => 'text', 'label' => 'contact.email'],
            ['name' => 'country', 'widget' => 'radio', 'label' => 'contact.country'],
        ],
        'translations' => [
            'en' => ['contact.email' => 'E-mail', 'contact.country' => 'Country'],
            'pl' => ['contact.email' => 'Adres e-mail', 'contact.country' => 'Kraj'],
        ],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testItDrawsTheFormItIsAskedFor(): void
    {
        // GIVEN a form somebody described
        $id = $this->plant();

        // WHEN
        $crawler = $this->client->request('GET', \sprintf('/forms/%s', $id));

        // THEN the page carries the form and its controls
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertSame($id, $crawler->filter('body')->attr('data-form'));
        self::assertCount(2, $crawler->filter('[data-item]'));
        self::assertSame('E-mail *', $crawler->filter('label[for="item-email"]')->text());
    }

    public function testTheLanguageComesFromTheUrlWhenTheUrlSaysSo(): void
    {
        // GIVEN
        $id = $this->plant();

        // WHEN the locale is pinned in the path
        $crawler = $this->client->request('GET', \sprintf('/pl/forms/%s', $id));

        // THEN
        self::assertResponseIsSuccessful();
        self::assertSame('Adres e-mail *', $crawler->filter('label[for="item-email"]')->text());
        self::assertSame('pl', $crawler->filter('html')->attr('lang'));
    }

    public function testOtherwiseTheBrowserIsAskedAndTheAnswerIsVariedOn(): void
    {
        // GIVEN
        $id = $this->plant();

        // WHEN nothing pins the language and the browser states a preference
        $crawler = $this->client->request('GET', \sprintf('/forms/%s', $id), server: ['HTTP_ACCEPT_LANGUAGE' => 'pl-PL,pl;q=0.9,en;q=0.8']);

        // THEN it is honoured...
        self::assertSame('Adres e-mail *', $crawler->filter('label[for="item-email"]')->text());

        // ...and the answer says it depends on that header, so a cache cannot
        // hand one language to everybody
        self::assertResponseHeaderSame('Vary', 'Accept-Language');
    }

    public function testALanguageNobodyTranslatedFallsBackToTheDocumentsDefault(): void
    {
        // GIVEN a document with English and Polish only
        $id = $this->plant();

        // WHEN somebody asks for German
        $crawler = $this->client->request('GET', \sprintf('/de/forms/%s', $id));

        // THEN the default locale answers rather than a blank label
        self::assertSame('E-mail *', $crawler->filter('label[for="item-email"]')->text());
    }

    public function testAFormNobodyDescribedCannotBeDrawn(): void
    {
        // GIVEN a form created without a presentation
        $id = $this->plant(withPresentation: false);

        // WHEN / THEN
        $this->client->request('GET', \sprintf('/forms/%s', $id));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAPresentationForAKitNobodyHereDrawsIsAConflictNotABlankPage(): void
    {
        // GIVEN a document written for somebody else's kit — valid, and not ours
        $id = $this->plant(engine: 'someones-vue-kit');

        // WHEN
        $this->client->request('GET', \sprintf('/forms/%s', $id));

        // THEN the document is fine; this deployment simply cannot draw it
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('urn:problem:ingot-forms:presentation-engine-unsupported', $body['type']);
    }

    public function testAnUnknownFormIsNotFoundAndAnExpiredOneIsGone(): void
    {
        // GIVEN / WHEN / THEN
        $this->client->request('GET', \sprintf('/forms/%s', Uuid::v7()->toRfc4122()));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', \sprintf('/forms/%s', $this->plant(expired: true)));
        self::assertResponseStatusCodeSame(410);
    }

    private function plant(bool $withPresentation = true, string $engine = 'core-html', bool $expired = false): string
    {
        $id = FormId::next();
        $container = self::getContainer();

        $definitions = $container->get(FormDefinitionProcessor::class);
        self::assertInstanceOf(FormDefinitionProcessor::class, $definitions);
        $presentations = $container->get(PresentationProcessor::class);
        self::assertInstanceOf(PresentationProcessor::class, $presentations);
        $repository = $container->get(DoctrineFormRepository::class);
        self::assertInstanceOf(DoctrineFormRepository::class, $repository);

        $document = self::PRESENTATION;
        $document['engine'] = $engine;

        $repository->add(new Form(
            $id,
            $definitions->document($definitions->parse(self::DEFINITION)),
            $expired ? ExpireDate::at(new \DateTimeImmutable('-1 hour')) : ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $withPresentation ? $presentations->document($presentations->parse($document)) : null,
            new PresentationRules(new Engines([new CoreHtmlEngine()])),
        ));

        return (string) $id;
    }
}
