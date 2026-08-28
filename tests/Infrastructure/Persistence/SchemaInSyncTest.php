<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The mapping and the migrated database say the same thing.
 *
 * Worth a test because it stopped being true once and nothing noticed for weeks.
 * A migration added the foreign key that makes a revision leave with its form,
 * the ORM had no way to declare it, and `doctrine:schema:validate` reported a
 * difference ever after — which costs two things. A *real* drift hides in noise
 * somebody has learnt to ignore, and a `schema:update --force` typed in a hurry
 * takes the reported difference at its word and drops the cascade the history
 * depends on.
 *
 * So it is asserted rather than checked by hand: a mapping that grows a column a
 * migration did not, or a migration that adds what the mapping cannot say, fails
 * here with the statements it would have taken to reconcile them.
 */
final class SchemaInSyncTest extends KernelTestCase
{
    public function testTheMappingAndTheDatabaseAgreeOnEveryColumnAndConstraint(): void
    {
        // GIVEN the mapping this code runs on, and the database the migrations
        // built
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        // Minus the migrations bundle's own bookkeeping table, which no mapping
        // describes and none should. `doctrine:schema:validate` leaves it out the
        // same way — its filter is only switched on while a console command runs,
        // so a test has to say so itself.
        $configuration = $entityManager->getConnection()->getConfiguration();
        $previous = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(
            static fn(AbstractAsset|string $asset): bool => (\is_string($asset) ? $asset : $asset->getName()) !== 'doctrine_migration_versions',
        );

        $validator = new SchemaValidator($entityManager);

        // THEN the mapping is coherent on its own terms
        self::assertSame([], $validator->validateMapping());

        // AND there is nothing left to do to the database to make it match: an
        // empty list is the whole assertion, and a failure prints the statements
        // that would have been needed, which is also the diagnosis
        try {
            self::assertSame([], $validator->getUpdateSchemaList());
        } finally {
            // The connection's configuration outlives this test, so what was
            // borrowed goes back.
            $configuration->setSchemaAssetsFilter($previous);
        }
    }
}
