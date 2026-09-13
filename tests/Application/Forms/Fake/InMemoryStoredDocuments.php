<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Template\PublishedVersion;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\PresentationId;

/**
 * The documents and the two histories over them, in memory — both ports, because
 * in production one adapter answers both and the rows are the same rows.
 */
final class InMemoryStoredDocuments implements StoredDocuments, TemplateVersions
{
    /** @var array<string, StoredDefinition> */
    public array $definitions = [];

    /** @var array<string, StoredPresentation> */
    public array $presentations = [];

    public function addDefinition(StoredDefinition $definition): void
    {
        $this->definitions[(string) $definition->id()] = $definition;
    }

    public function addPresentation(StoredPresentation $presentation): void
    {
        $this->presentations[(string) $presentation->id()] = $presentation;
    }

    public function definition(DefinitionId $id): StoredDefinition
    {
        return $this->definitions[(string) $id] ?? throw DocumentNotStored::definition($id);
    }

    public function presentation(PresentationId $id): StoredPresentation
    {
        return $this->presentations[(string) $id] ?? throw DocumentNotStored::presentation($id);
    }

    public function collect(DefinitionId $definition, ?PresentationId $presentation): void
    {
        unset($this->definitions[(string) $definition]);

        if ($presentation !== null) {
            unset($this->presentations[(string) $presentation]);
        }
    }

    public function collectVersionsOf(FormTemplateId $template): void
    {
        foreach ($this->of($this->definitions, $template) as $document) {
            unset($this->definitions[(string) $document->id()]);
        }

        foreach ($this->of($this->presentations, $template) as $document) {
            unset($this->presentations[(string) $document->id()]);
        }
    }

    public function nextDefinitionSeq(FormTemplateId $template): int
    {
        return $this->highest($this->of($this->definitions, $template)) + 1;
    }

    public function nextPresentationSeq(FormTemplateId $template): int
    {
        return $this->highest($this->of($this->presentations, $template)) + 1;
    }

    public function definitionAt(FormTemplateId $template, int $seq): StoredDefinition
    {
        foreach ($this->of($this->definitions, $template) as $document) {
            if ($document->version()?->seq() === $seq) {
                return $document;
            }
        }

        throw DocumentNotStored::definitionVersion($template, $seq);
    }

    public function presentationAt(FormTemplateId $template, int $seq): StoredPresentation
    {
        foreach ($this->of($this->presentations, $template) as $document) {
            if ($document->version()?->seq() === $seq) {
                return $document;
            }
        }

        throw DocumentNotStored::presentationVersion($template, $seq);
    }

    public function definitionsOf(FormTemplateId $template): array
    {
        return $this->listed($this->of($this->definitions, $template));
    }

    public function presentationsOf(FormTemplateId $template): array
    {
        return $this->listed($this->of($this->presentations, $template));
    }

    /**
     * @template T of StoredDefinition|StoredPresentation
     *
     * @param array<string, T> $documents
     *
     * @return list<T>
     */
    private function of(array $documents, FormTemplateId $template): array
    {
        return array_values(array_filter(
            $documents,
            static fn(StoredDefinition|StoredPresentation $document): bool => $document->version()?->template()->equals($template) === true,
        ));
    }

    /**
     * @param list<StoredDefinition|StoredPresentation> $documents
     */
    private function highest(array $documents): int
    {
        return array_reduce(
            $documents,
            static fn(int $highest, StoredDefinition|StoredPresentation $document): int => max($highest, $document->version()?->seq() ?? 0),
            0,
        );
    }

    /**
     * @param list<StoredDefinition|StoredPresentation> $documents
     *
     * @return list<PublishedVersion>
     */
    private function listed(array $documents): array
    {
        $versions = array_map(
            static fn(StoredDefinition|StoredPresentation $document): PublishedVersion => new PublishedVersion(
                $document->version()?->seq() ?? 0,
                $document->createdAt(),
                $document->createdBy(),
            ),
            $documents,
        );
        usort($versions, static fn(PublishedVersion $a, PublishedVersion $b): int => $b->seq <=> $a->seq);

        return $versions;
    }
}
