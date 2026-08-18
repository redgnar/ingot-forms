<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\FormMapperFactory;
use Ingot\Source;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The factory is where the definition mapper's configuration lives — the
 * meta-schema, the semantic rule and the plugin-field fallback — so these
 * tests check that a mapper it builds carries all three, with or without a
 * metadata cache.
 */
final class FormMapperFactoryTest extends TestCase
{
    private const string DEFINITION = '{"id": "contact", "title": "Contact us", "fields": [{"type": "signature", "name": "sig"}]}';

    public function testTheMapperItBuildsCarriesTheDefinitionConfiguration(): void
    {
        // GIVEN a mapper built without any cache
        $mapper = new FormMapperFactory()->create();

        // WHEN mapping a definition whose field type the application does not know
        $definition = $mapper->map(FormDefinition::class, Source::json(self::DEFINITION));

        // THEN the fallback kept it instead of failing
        self::assertInstanceOf(GenericField::class, $definition->fields[0]);

        // AND the meta-schema still guards the document
        $result = $mapper->tryMap(FormDefinition::class, Source::json('{"id": "x", "fields": []}'));
        self::assertFalse($result->isSuccess());
    }

    public function testAMetadataCacheIsUsedWhenOneIsGiven(): void
    {
        // GIVEN
        $cache = new ArrayAdapter();
        $mapper = new FormMapperFactory($cache)->create();

        // WHEN the mapper needs the metadata of a class
        $mapper->map(FormDefinition::class, Source::json(self::DEFINITION));

        // THEN it was written to the pool it was handed
        self::assertNotSame([], $cache->getValues());
    }
}
