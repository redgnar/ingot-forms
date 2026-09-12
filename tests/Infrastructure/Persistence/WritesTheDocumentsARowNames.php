<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Persistence\FormDefinitionRecord;
use App\Infrastructure\Persistence\FormPresentationRecord;
use App\Infrastructure\Persistence\FormRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * For the handful of tests that write a form row straight through Doctrine.
 *
 * They all exist for one reason — a row holding what the API would no longer
 * accept, which is the only way to test what happens when the rules move on —
 * and since a form names its documents rather than holding them, each of them
 * has to keep those documents first or the row has nothing to point at.
 *
 * One place rather than four copies, because the four are the same eight lines
 * and the interesting part of each test is the document, never the plumbing.
 */
trait WritesTheDocumentsARowNames
{
    /**
     * Keeps the two documents and points the row at them. Flushed here, because
     * the foreign key means the row cannot be written until they are there.
     */
    private static function documentsFor(
        EntityManagerInterface $entityManager,
        FormRecord $record,
        string $definition,
        ?string $presentation = null,
    ): void {
        $kept = new FormDefinitionRecord();
        $kept->id = Uuid::v7();
        $kept->document = $definition;
        $kept->createdAt = new \DateTimeImmutable();
        $entityManager->persist($kept);
        $record->definitionId = $kept->id;

        if ($presentation !== null) {
            $shown = new FormPresentationRecord();
            $shown->id = Uuid::v7();
            $shown->document = $presentation;
            $shown->createdAt = new \DateTimeImmutable();
            $entityManager->persist($shown);
            $record->presentationId = $shown->id;
        }

        $entityManager->flush();
    }
}
