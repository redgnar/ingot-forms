<?php

declare(strict_types=1);

namespace App\Http\Request;

use Ingot\Coercion;
use Ingot\MapperBuilder;
use Ingot\Source;
use Ingot\TreeMapper;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Maps request DTOs through ingot, with the mapping rules each part of an HTTP
 * request deserves:
 *
 * - **body**: strict. JSON carries real types, and an unexpected key is a
 *   client bug worth reporting — the published schema says `additionalProperties:
 *   false` because the mapper behaves that way.
 * - **query**: lax. Every query value arrives as a string ("10", not 10), and
 *   unknown parameters (tracking, proxies) are ignored the way HTTP clients
 *   expect.
 */
final class RequestMapper
{
    private readonly TreeMapper $bodyMapper;

    private readonly TreeMapper $queryMapper;

    /**
     * @param iterable<RequestRule> $rules semantic rules, one per DTO that needs one
     */
    public function __construct(
        #[AutowireIterator('app.request_rule')]
        iterable $rules = [],
        ?CacheItemPoolInterface $mapperCache = null,
    ) {
        $body = MapperBuilder::create();
        $query = MapperBuilder::create()->withCoercion(Coercion::Lax);

        foreach ($rules as $rule) {
            $body = $body->withValidator($rule->target(), $rule);
            $query = $query->withValidator($rule->target(), $rule);
        }

        if ($mapperCache !== null) {
            $body = $body->withCache($mapperCache);
            $query = $query->withCache($mapperCache);
        }

        $this->bodyMapper = $body->build();
        $this->queryMapper = $query->build();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $target
     *
     * @return T
     *
     * @throws RequestNotValid
     */
    public function fromBody(string $target, string $json): object
    {
        return $this->map($this->bodyMapper, $target, Source::json($json));
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $target
     * @param array<string, mixed> $values
     *
     * @return T
     *
     * @throws RequestNotValid
     */
    public function fromQuery(string $target, array $values): object
    {
        // A query string is a mapping even when empty — PHP cannot tell an
        // empty map from an empty list, so say it explicitly.
        return $this->map($this->queryMapper, $target, Source::array((object) $values));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $target
     *
     * @return T
     *
     * @throws RequestNotValid
     */
    private function map(TreeMapper $mapper, string $target, Source $source): object
    {
        $result = $mapper->tryMap($target, $source);

        if (!$result->isSuccess()) {
            throw new RequestNotValid($result->errors());
        }

        return $result->value();
    }
}
