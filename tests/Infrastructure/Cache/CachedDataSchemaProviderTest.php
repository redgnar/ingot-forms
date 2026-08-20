<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Cache;

use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Cache\CachedDataSchemaProvider;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

/**
 * What the derived-schema cache may and may not skip.
 *
 * A definition is immutable and a UUID is never reused, so an entry is never
 * wrong about the form it belongs to. It can still be wrong about the rules:
 * the key names the form and the mode, not the code that derived the
 * document — which is why the pool is thrown away whenever those rules change
 * (`make cache-clear`, and on every deploy), and why nothing is kept past the
 * process in dev.
 */
final class CachedDataSchemaProviderTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email","required":true},{"type":"checkbox","name":"terms","mustBeChecked":true}]}';

    private InMemoryForms $forms;
    private ArrayAdapter $pool;
    private CachedDataSchemaProvider $schemas;

    protected function setUp(): void
    {
        $this->forms = new InMemoryForms();
        $this->pool = new ArrayAdapter(storeSerialized: false);
        $this->schemas = new CachedDataSchemaProvider($this->pool, $this->forms, new DataSchemaDeriver());
    }

    public function testEachModeIsItsOwnEntry(): void
    {
        // GIVEN a form whose consent has to be ticked to finish it
        $id = $this->form();

        // WHEN both contracts are asked for
        $strict = $this->schemas->json($id, DeriveMode::Strict);
        $draft = $this->schemas->json($id, DeriveMode::Draft);

        // THEN each answers with its own rules, and neither is served the
        // other's document
        self::assertStringContainsString('"const":true', $strict);
        self::assertStringNotContainsString('"const":true', $draft);
        self::assertStringContainsString('"required":["email"]', $strict);
        self::assertStringNotContainsString('"required"', $draft);
    }

    public function testTheDocumentIsDerivedOnceAndThenReused(): void
    {
        // GIVEN a schema that has been served once
        $id = $this->form();
        $first = $this->schemas->json($id, DeriveMode::Strict);

        // WHEN the entry in the pool is replaced by hand
        $item = $this->pool->getItem(\sprintf('form_schema.%s.%s', $id, 'Strict'));
        $this->pool->save($item->set('{"planted":true}'));

        // THEN that is what comes back: an entry is trusted, so a change to the
        // rules that derived it is only visible once the pool is cleared
        self::assertNotSame('{"planted":true}', $first);
        self::assertSame('{"planted":true}', $this->schemas->json($id, DeriveMode::Strict));
    }

    public function testTheSchemaHandedToTheValidatorIsTheDocumentThatWasServed(): void
    {
        // GIVEN
        $id = $this->form();
        $form = $this->forms->get($id);

        // WHEN the validation path asks for the schema it already holds the
        // definition for
        $schema = $this->schemas->schemaFor($id, $form->definition()->structure(), DeriveMode::Draft);

        // THEN it is the same document the endpoint serves, not a second
        // derivation of it
        self::assertSame(
            $this->schemas->json($id, DeriveMode::Draft),
            json_encode($schema->document, \JSON_THROW_ON_ERROR),
        );
    }

    public function testAFormThatIsNotThereIsStillReportedMissing(): void
    {
        // GIVEN / WHEN / THEN the cache skips deriving, never the guard
        $this->expectException(FormNotFound::class);
        $this->schemas->json(FormId::fromString(Uuid::v7()->toRfc4122()), DeriveMode::Strict);
    }

    public function testAnExpiredFormIsGoneEvenWhenItsSchemaWasCached(): void
    {
        // GIVEN a form whose schema was served while it was still alive
        $id = $this->form();
        $this->schemas->json($id, DeriveMode::Strict);

        // WHEN it expires
        $this->forms->add(new Form(
            $id,
            self::definition(),
            ExpireDate::at(new \DateTimeImmutable('-1 hour')),
        ));

        // THEN existence and expiry are re-checked on every call
        $this->expectException(FormGone::class);
        $this->schemas->json($id, DeriveMode::Strict);
    }

    private function form(): FormId
    {
        $id = FormId::fromString(Uuid::v7()->toRfc4122());
        $this->forms->add(new Form($id, self::definition(), ExpireDate::at(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function definition(): Definition
    {
        return Definition::stored(self::DEFINITION, new FormDefinitionProcessor(new FormMapperFactory()->create()));
    }
}
