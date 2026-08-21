<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Web;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Presentation\Engine\BootstrapEngine;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use App\Infrastructure\Persistence\FormRecord;
use Doctrine\ORM\EntityManagerInterface;
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
            ['widget' => 'confirm', 'label' => 'contact.send'],
        ],
        'translations' => [
            'en' => ['contact.email' => 'E-mail', 'contact.country' => 'Country', 'contact.send' => 'Send'],
            'pl' => ['contact.email' => 'Adres e-mail', 'contact.country' => 'Kraj', 'contact.send' => 'Wyślij'],
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

    public function testTheKitADocumentNamesIsTheKitThatDrawsIt(): void
    {
        // GIVEN the same form described twice, once per kit this deployment has
        $plain = $this->plant();
        $rich = $this->plantRich();

        // WHEN each page is opened
        $plainPage = $this->client->request('GET', \sprintf('/forms/%s', $plain));
        self::assertResponseIsSuccessful();
        $richPage = $this->client->request('GET', \sprintf('/forms/%s', $rich));
        self::assertResponseIsSuccessful();

        // THEN one endpoint, two kits: the document decides which markup a
        // person gets, and neither page borrows the other's
        self::assertCount(0, $plainPage->filter('script[type="importmap"]'));
        self::assertCount(1, $plainPage->filter('[data-action="confirm"]'));
        self::assertCount(0, $plainPage->filter('.card'));

        self::assertCount(1, $richPage->filter('script[type="importmap"]'));
        self::assertCount(1, $richPage->filter('[data-action="click->form#confirm"]'));
        self::assertCount(1, $richPage->filter('.card'));
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
        self::assertSame('Wyślij', $crawler->filter('[data-action="confirm"]')->text());
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

    public function testWhatThePageSaysInItsOwnNameIsTranslatedToo(): void
    {
        // GIVEN a form described for each kit
        $plain = $this->plant();
        $rich = $this->plantRich();

        // WHEN both pages are asked for in Polish
        $plainPage = $this->client->request('GET', \sprintf('/pl/forms/%s', $plain));
        $richPage = $this->client->request('GET', \sprintf('/pl/forms/%s', $rich));

        // THEN the sentences this application invented come out of its own
        // catalogue, in the language the request negotiated — the presentation's
        // codes are one catalogue, the page's own words are another
        self::assertStringContainsString('Twoje odpowiedzi są zapisane', $plainPage->filter('#form-saved')->text());
        self::assertStringContainsString('Twoje odpowiedzi są zapisane', $richPage->filter('[data-form-target="saved"]')->text());
        self::assertSame('Żądanie zostało odrzucone.', $richPage->filter('[data-controller="form"]')->attr('data-form-refused-value'));

        // AND in English when nobody asked for anything else
        $english = $this->client->request('GET', \sprintf('/forms/%s', $plain));
        self::assertStringContainsString('Your answers are saved', $english->filter('#form-saved')->text());
    }

    public function testAPageThatCannotBeDrawnAlsoSpeaksTheLanguageAskedFor(): void
    {
        // GIVEN / WHEN a form nobody has
        $page = $this->client->request('GET', \sprintf('/pl/forms/%s', Uuid::v7()->toRfc4122()));

        // THEN even the refusal is a page, in Polish, with the status that says
        // what happened
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Nie ma takiego formularza.', $page->filter('p')->text());
        self::assertSame('pl', $page->filter('html')->attr('lang'));
    }

    public function testAFormNobodyDescribedCannotBeDrawn(): void
    {
        // GIVEN a form created without a presentation
        $id = $this->plant(withPresentation: false);

        // WHEN / THEN it says what is missing, as a page rather than a document
        $this->client->request('GET', \sprintf('/forms/%s', $id));

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('how to show this form', (string) $this->client->getResponse()->getContent());
    }

    public function testAPresentationForAKitNobodyHereDrawsIsAConflictNotABlankPage(): void
    {
        // GIVEN a document written for somebody else's kit — valid, and not ours
        $id = $this->plant(engine: 'someones-vue-kit');

        // WHEN
        $this->client->request('GET', \sprintf('/forms/%s', $id));

        // THEN the document is fine; this deployment simply cannot draw it — and
        // it says so as a page, because a browser is not a client of the API's
        // problem+json contract
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertStringContainsString('someones-vue-kit', (string) $this->client->getResponse()->getContent());
    }

    public function testAnEarlierVersionIsTheSamePageDrawnFromThatSave(): void
    {
        // GIVEN a form saved twice through the API
        $id = $this->plant();
        $this->save($id, '{"email": "ada@example.com"}');
        $this->save($id, '{"email": "eve@example.com"}');

        // WHEN the page for the first save is asked for
        $page = $this->client->request('GET', \sprintf('/forms/%s/versions/1', $id));

        // THEN it is the same page, drawn from that document and only readable —
        // which is what makes every control, list and file right without a line of
        // new code
        self::assertResponseIsSuccessful();
        self::assertSame('ada@example.com', $page->filter('#item-email')->attr('value'));
        self::assertNotNull($page->filter('#item-email')->attr('disabled'));

        // AND the way out is on it: put this version back, or go to the current one
        self::assertSame('1', $page->filter('[data-history-restore]')->attr('data-history-restore'));
        self::assertStringContainsString($id, (string) $page->filter('.viewing a')->attr('href'));

        // ...while the current page is unchanged, because looking is not saving
        $current = $this->client->request('GET', \sprintf('/forms/%s', $id));
        self::assertSame('eve@example.com', $current->filter('#item-email')->attr('value'));
        self::assertNull($current->filter('#item-email')->attr('disabled'));
    }

    public function testAVersionThisFormNeverHadIsNotAPage(): void
    {
        // GIVEN a form saved once
        $id = $this->plant();
        $this->save($id, '{"email": "ada@example.com"}');

        // WHEN / THEN asking for a save that never happened is the same kind of
        // mistake as asking for a form that is not there — answered with a page,
        // because a person is no client of RFC 9457
        $this->client->request('GET', \sprintf('/forms/%s/versions/7', $id));
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('no such earlier version', (string) $this->client->getResponse()->getContent());
    }

    public function testAnUnknownFormIsNotFoundAndAnExpiredOneIsGone(): void
    {
        // GIVEN / WHEN / THEN
        $this->client->request('GET', \sprintf('/forms/%s', Uuid::v7()->toRfc4122()));
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

        $this->client->request('GET', \sprintf('/forms/%s', $this->plant(expired: true)));
        self::assertResponseStatusCodeSame(410);
        self::assertStringContainsString('expired', (string) $this->client->getResponse()->getContent());
    }

    public function testAFormStoredUnderOlderRulesSaysSoAsAPage(): void
    {
        // GIVEN a row written when a presentation needed no way to confirm
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

        // WHEN somebody opens it
        $this->client->request('GET', \sprintf('/forms/%s', $id));

        // THEN a page, and a status that says whose problem it is — not a 500
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertStringContainsString('rules that have since changed', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The same form, described for the richer kit — its own words for grouping
     * and for the control a searchable choice gets.
     */
    private function save(string $id, string $values): void
    {
        $this->client->request(
            'PUT',
            \sprintf('/api/forms/%s/data', $id),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $values,
        );
        self::assertResponseStatusCodeSame(204);
    }

    private function plantRich(): string
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
        $document['engine'] = 'bootstrap';
        $document['items'] = [
            ['widget' => 'card', 'items' => [
                ['name' => 'email', 'widget' => 'text', 'label' => 'contact.email'],
                ['name' => 'country', 'widget' => 'autocomplete', 'label' => 'contact.country'],
            ]],
            ['widget' => 'confirm', 'label' => 'contact.send'],
        ];

        $repository->add(new Form(
            $id,
            $definitions->document($definitions->parse(self::DEFINITION)),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $presentations->document($presentations->parse($document)),
            new PresentationRules(new Engines([new BootstrapEngine()])),
        ));

        return (string) $id;
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
