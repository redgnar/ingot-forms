<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Api;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelBootTest extends KernelTestCase
{
    public function testKernelBootsWithTheIngotLibraryAvailable(): void
    {
        // GIVEN the test environment configuration

        // WHEN
        $kernel = self::bootKernel();

        // THEN the container is up and the path-repository dependency resolves
        self::assertSame('test', $kernel->getEnvironment());
        self::assertTrue(class_exists(\Ingot\MapperBuilder::class));
    }
}
