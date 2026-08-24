<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use App\Domain\Forms\MetaSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MetaSchemaApiTest extends WebTestCase
{
    /**
     * @return iterable<string, array{MetaSchema}>
     */
    public static function documents(): iterable
    {
        yield 'definition' => [MetaSchema::Definition];
        yield 'presentation' => [MetaSchema::Presentation];
    }

    #[DataProvider('documents')]
    public function testAContractAClientIsHeldToIsOneItCanFetch(MetaSchema $document): void
    {
        // GIVEN nothing: a meta-schema is fixed for a deployment
        $client = self::createClient();

        // WHEN it is asked for by the name the contract calls it
        $client->request('GET', \sprintf('/api/schemas/%s', $document->value));

        // THEN it comes back as a schema document, and byte for byte the one the
        // mapper enforces — a published contract naming a file inside this
        // repository is a contract its readers cannot reach
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/schema+json');
        self::assertSame($document->document(), (string) $client->getResponse()->getContent());
    }

    #[DataProvider('documents')]
    public function testTheServedDocumentIsAJsonSchema(MetaSchema $document): void
    {
        // GIVEN the meta-schema this API publishes
        $client = self::createClient();
        $client->request('GET', \sprintf('/api/schemas/%s', $document->value));

        // WHEN it is read the way a client validator would
        $schema = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        // THEN it says which dialect it is written in, so a validator knows how
        // to read it without being told out of band
        self::assertIsArray($schema);
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null);
        self::assertSame('object', $schema['type'] ?? null);
    }

    public function testThereIsNoMetaSchemaForADocumentThisApiDoesNotTake(): void
    {
        // GIVEN a name no document of this API goes by
        $client = self::createClient();

        // WHEN it is asked for
        $client->request('GET', '/api/schemas/values');

        // THEN there is nothing there. The derived values schema belongs to one
        // form and is served from that form's own address, so this is not a
        // second way to reach it.
        self::assertResponseStatusCodeSame(404);
    }
}
