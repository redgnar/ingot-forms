<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Application\Forms\Port\DataSchemas;
use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Schema\Schema;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Serves the derived data schema of a form as a JSON string.
 *
 * **Keyed by the definition and not by the form**, which is what a schema is a
 * function of: ten thousand forms made from one template share one entry per
 * mode instead of compiling ten thousand identical documents. A stored document
 * is immutable and its id is never reused, so no entry is ever wrong about what
 * it was derived from — no TTL and no invalidation. An entry outlives any one
 * form that used it, deliberately; when the last form made of a definition goes,
 * the document is collected and the entry becomes unreachable, exactly as an
 * entry of a deleted form always was.
 *
 * Existence and expiry are re-checked on every call — the cache only skips
 * re-deriving, never the gone/not-found guard.
 *
 * What the key cannot say is which rules derived the document. An entry
 * therefore stays right for exactly as long as {@see DataSchemaDeriver} does:
 * change what a definition derives and the pool has to be cleared with it
 * (`make cache-clear`, and on every deploy). In dev it is in-memory, so
 * nothing outlives the process that derived it.
 */
final class CachedDataSchemaProvider implements DataSchemas
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly FormRepository $repository,
        private readonly DataSchemaDeriver $deriver,
    ) {}

    /**
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     */
    public function json(FormId $formId, DeriveMode $mode): string
    {
        $record = $this->repository->get($formId);

        // The read had to happen anyway — this endpoint has an id and nothing
        // else, and existence and expiry are re-checked on every call — so
        // asking the form which definition it is made of costs nothing and keys
        // this beside every other form made of the same one.
        return $this->cached($record->definitionId(), $mode, static fn(): FormDefinition => $record->definition()->structure());
    }

    /**
     * The same cached document, handed back as a schema — for callers that
     * already hold the definition (a request being validated under the row
     * lock) and must not pay for another read to get it.
     */
    public function schemaFor(DefinitionId $definitionId, FormDefinition $definition, DeriveMode $mode): Schema
    {
        $document = json_decode($this->cached($definitionId, $mode, static fn(): FormDefinition => $definition), false, flags: \JSON_THROW_ON_ERROR);

        return Schema::fromDocument($document instanceof \stdClass ? $document : new \stdClass());
    }

    /**
     * @param callable(): FormDefinition $definition read only when the cache misses
     */
    private function cached(DefinitionId $definitionId, DeriveMode $mode, callable $definition): string
    {
        $item = $this->pool->getItem(\sprintf('form_schema.%s.%s', $definitionId, $mode->name));

        if ($item->isHit()) {
            $cached = $item->get();

            if (\is_string($cached)) {
                return $cached;
            }
        }

        $json = json_encode($this->deriver->derive($definition(), $mode)->document, \JSON_THROW_ON_ERROR);

        $item->set($json);
        $this->pool->save($item);

        return $json;
    }
}
