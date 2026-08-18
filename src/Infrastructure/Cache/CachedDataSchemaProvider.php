<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Application\Forms\Port\DataSchemas;
use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Schema\Schema;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Serves the derived data schema of a form as a JSON string. A form's
 * definition is immutable, so cache entries never go stale: no TTL, no
 * invalidation (UUIDs are never reused; entries of deleted forms are
 * unreachable). Existence and expiry are re-checked on every call — the
 * cache only skips re-deriving, never the gone/not-found guard.
 */
final class CachedDataSchemaProvider implements DataSchemas
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly FormRepository $repository,
        private readonly FormDefinitionProcessor $definitions,
        private readonly DataSchemaDeriver $deriver,
    ) {}

    /**
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     */
    public function json(FormId $formId, DeriveMode $mode): string
    {
        $record = $this->repository->get($formId);

        return $this->cached($formId, $mode, fn(): FormDefinition => $this->definitions->fromStored((string) $record->definition()));
    }

    /**
     * The same cached document, handed back as a schema — for callers that
     * already hold the definition (a request being validated under the row
     * lock) and must not pay for another read to get it.
     */
    public function schemaFor(FormId $formId, FormDefinition $definition, DeriveMode $mode): Schema
    {
        $document = json_decode($this->cached($formId, $mode, static fn(): FormDefinition => $definition), false, flags: \JSON_THROW_ON_ERROR);

        return Schema::fromDocument($document instanceof \stdClass ? $document : new \stdClass());
    }

    /**
     * @param callable(): FormDefinition $definition read only when the cache misses
     */
    private function cached(FormId $formId, DeriveMode $mode, callable $definition): string
    {
        $item = $this->pool->getItem(\sprintf('form_schema.%s.%s', $formId, $mode->name));

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
