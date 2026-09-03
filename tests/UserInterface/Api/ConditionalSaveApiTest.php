<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Store this only if the form is still what I read", over HTTP.
 *
 * The exchange is the one HTTP already has: read the values, keep the `ETag`,
 * hand it back in `If-Match`, and be told `412` instead of replacing a document
 * somebody else saved in between. Everything about it is optional — a client that
 * sends no header gets exactly what every client got before this existed, which
 * is what the last case here is for.
 */
final class ConditionalSaveApiTest extends WebTestCase
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

    public function testTheValuesCarryTheRevisionTheyAre(): void
    {
        // GIVEN a form saved twice
        $id = $this->createForm();
        $this->save($id, '{"age": 36}');
        $this->save($id, '{"age": 37}');

        // WHEN the values are read
        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));

        // THEN the tag is the number of the save they are, which is what a
        // conditional write is judged against
        self::assertResponseStatusCodeSame(200);
        self::assertSame('"2"', $this->client->getResponse()->headers->get('ETag'));
    }

    public function testTheEnvelopeSaysWhichRevisionTheFormIsAt(): void
    {
        // GIVEN a form saved once
        $id = $this->createForm();
        $this->save($id, '{"age": 36}');

        // WHEN the owning system reads it
        $this->client->request('GET', \sprintf('/api/manage/forms/%s', $id));

        // THEN it needs no second request to the history to name the newest save
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->responseBody()['revision'] ?? null);
    }

    public function testAFormNobodyHasFilledInIsAtRevisionZero(): void
    {
        // GIVEN a form with nothing in it
        $id = $this->createForm();

        // WHEN it is read
        $this->client->request('GET', \sprintf('/api/manage/forms/%s', $id));

        // THEN there is a number to hold, even though there is no document to
        // read a tag off — which is why `If-Match: "0"` has to be legal
        self::assertSame(0, $this->responseBody()['revision'] ?? null);
    }

    public function testASaveHoldingTheCurrentRevisionIsStored(): void
    {
        // GIVEN a form somebody read at revision 1
        $id = $this->createForm();
        $this->save($id, '{"age": 36}');

        // WHEN they write back saying so
        $this->save($id, '{"age": 37}', ifMatch: '"1"');

        // THEN it is an ordinary save
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));
        self::assertSame('"2"', $this->client->getResponse()->headers->get('ETag'));
    }

    public function testTheSecondOfTwoPeopleEditingOneFormIsToldRatherThanIgnored(): void
    {
        // GIVEN two people who both read the form at revision 1
        $id = $this->createForm();
        $this->save($id, '{"age": 36}');
        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));
        $tag = $this->client->getResponse()->headers->get('ETag');
        self::assertSame('"1"', $tag);

        // AND the first one saves
        $this->save($id, '{"age": 37}', ifMatch: $tag);
        self::assertResponseStatusCodeSame(204);

        // WHEN the second one saves, still holding what they read
        $this->save($id, '{"age": 99}', ifMatch: $tag);

        // THEN they are refused, and told why in the one format this API has
        self::assertResponseStatusCodeSame(412);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('urn:problem:ingot-forms:form-moved-on', $this->responseBody()['type'] ?? null);

        // AND nothing of theirs was stored: the first person's work is intact
        $this->client->request('GET', \sprintf('/api/forms/%s/data', $id));
        self::assertSame(37, $this->responseBody()['age'] ?? null);
    }

    public function testASaveMayAskThatNobodyHasFilledTheFormInYet(): void
    {
        // GIVEN a form somebody has already answered
        $id = $this->createForm();
        $this->save($id, '{"age": 36}');

        // WHEN a second person, who opened it while it was empty, sends theirs
        $this->save($id, '{"age": 99}', ifMatch: '"0"');

        // THEN they are told — the one case with no document to read a tag from,
        // and the only reason `"0"` is a legal expectation
        self::assertResponseStatusCodeSame(412);
    }

    public function testConfirmingHoldsTheSameWay(): void
    {
        // GIVEN a form read at revision 1 and saved again by somebody else
        $id = $this->createForm();
        $this->save($id, '{"age": 36, "email": "ada@example.com"}');
        $this->save($id, '{"age": 37, "email": "grace@example.com"}');

        // WHEN the first reader confirms what they think they read
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id), server: ['HTTP_IF_MATCH' => '"1"']);

        // THEN the door is still open, which matters more here than on a save:
        // a form locked on a document nobody read cannot be put back
        self::assertResponseStatusCodeSame(412);
        $this->client->request('GET', \sprintf('/api/manage/forms/%s', $id));
        self::assertSame('draft', $this->responseBody()['status'] ?? null);

        // AND holding the revision that is actually there closes it
        $this->client->request('POST', \sprintf('/api/forms/%s/confirm', $id), server: ['HTTP_IF_MATCH' => '"2"']);
        self::assertResponseStatusCodeSame(204);
    }

    public function testAnyRevisionIsAcceptedWhenTheCallerWillTakeAnyOfThem(): void
    {
        // GIVEN a form at revision 1
        $id = $this->createForm();
        $this->save($id, '{"age": 36}');

        // WHEN a caller says it would accept either of two, one of which is there
        $this->save($id, '{"age": 37}', ifMatch: '"1", "2"');

        // THEN it is stored: the header allows a list, so refusing one would be
        // refusing a legal request
        self::assertResponseStatusCodeSame(204);
    }

    public function testAStarIsSatisfiedByTheFormBeingThere(): void
    {
        // GIVEN a form
        $id = $this->createForm();
        $this->save($id, '{"age": 36}');

        // WHEN a caller asks only that it exists
        $this->save($id, '{"age": 37}', ifMatch: '*');

        // THEN it is stored — every request here already requires that, so this
        // asks for nothing extra
        self::assertResponseStatusCodeSame(204);
    }

    #[DataProvider('headersThatAreNotRevisions')]
    public function testAPreconditionNobodyCanReadIsRefusedRatherThanIgnored(string $header): void
    {
        // GIVEN a form
        $id = $this->createForm();
        $this->save($id, '{"age": 36}');

        // WHEN something arrives in `If-Match` that is not a revision
        $this->save($id, '{"age": 37}', ifMatch: $header);

        // THEN it is a bad request, not a save that quietly went through
        // unconditionally: a client that meant to protect somebody's work and
        // spelled it wrong has to hear about it
        self::assertResponseStatusCodeSame(400);
        self::assertSame('urn:problem:ingot-forms:precondition-not-readable', $this->responseBody()['type'] ?? null);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function headersThatAreNotRevisions(): iterable
    {
        // An unquoted number is not an entity tag, however obvious it looks.
        yield 'an unquoted revision' => ['1'];

        // A weak validator means "close enough for display", which is not a
        // comparison this endpoint makes.
        yield 'a weak validator' => ['W/"1"'];

        yield 'a hash of something' => ['"d41d8cd98f00b204e9800998ecf8427e"'];

        yield 'a negative revision' => ['"-1"'];

        yield 'one good tag and one that is not' => ['"1", nonsense'];
    }

    public function testAClientThatSaysNothingSavesUnconditionally(): void
    {
        // GIVEN a form two people have saved
        $id = $this->createForm();
        $this->save($id, '{"age": 36}');
        $this->save($id, '{"age": 37}');

        // WHEN a client that has never heard of any of this saves
        $this->save($id, '{"age": 38}');

        // THEN it goes through: a precondition nobody asked for would break
        // every existing caller to protect a case they do not have
        self::assertResponseStatusCodeSame(204);
    }

    private function createForm(): string
    {
        $this->client->request(
            'POST',
            '/api/manage/forms',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => self::DEFINITION,
            ], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        $id = $this->responseBody()['id'] ?? '';
        self::assertIsString($id);

        return $id;
    }

    private function save(string $id, string $values, ?string $ifMatch = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($ifMatch !== null) {
            $server['HTTP_IF_MATCH'] = $ifMatch;
        }

        $this->client->request('PUT', \sprintf('/api/forms/%s/data', $id), server: $server, content: $values);
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
