<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Infrastructure\Persistence\FormRepository;
use Ingot\Schema\Schema;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Serves the derived data schema of a form as a JSON string. A form's
 * definition is immutable, so cache entries never go stale: no TTL, no
 * invalidation (UUIDs are never reused; entries of deleted forms are
 * unreachable). Existence and expiry are re-checked on every call — the
 * cache only skips re-deriving, never the gone/not-found guard.
 */
final class CachedDataSchemaProvider
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly FormRepository $repository,
        private readonly FormDefinitionProcessor $processor,
        private readonly DataSchemaDeriver $deriver,
    ) {}

    /**
     * @throws \App\Infrastructure\Persistence\FormNotFound
     * @throws \App\Infrastructure\Persistence\FormGone
     */
    public function schemaJson(Uuid $formId, DeriveMode $mode): string
    {
        $record = $this->repository->get($formId);

        return $this->cached($formId, $mode, fn(): FormDefinition => $this->processor->fromStored($record->definition()));
    }

    /**
     * The same cached document, handed back as a schema — for callers that
     * already hold the definition (a request being validated under the row
     * lock) and must not pay for another read to get it.
     */
    public function schemaFor(Uuid $formId, FormDefinition $definition, DeriveMode $mode): Schema
    {
        $document = json_decode($this->cached($formId, $mode, static fn(): FormDefinition => $definition), false, flags: \JSON_THROW_ON_ERROR);

        return Schema::fromDocument($document instanceof \stdClass ? $document : new \stdClass());
    }

    /**
     * @param callable(): FormDefinition $definition read only when the cache misses
     */
    private function cached(Uuid $formId, DeriveMode $mode, callable $definition): string
    {
        $item = $this->pool->getItem(\sprintf('form_schema.%s.%s', $formId->toRfc4122(), $mode->name));

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
