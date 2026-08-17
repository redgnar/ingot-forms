<?php

declare(strict_types=1);

/*
 * Renders the API contract (openapi.yaml at the repo root) into docs/:
 *
 *   docs/openapi.yaml  the effective contract — hand-written paths and prose,
 *                      with every `x-ingot-schema` marker replaced by the
 *                      schema ingot generates from that request DTO. This is
 *                      the document the contract tests validate traffic
 *                      against, so the DTOs cannot drift from the published
 *                      API in either direction.
 *   docs/api.md        a browsable Markdown reference
 *
 * Run it through `make docs`; never edit docs/ by hand. Dev tooling, hence
 * tools/ and not src/ — it uses require-dev packages the application itself
 * must not depend on.
 */

use cebe\openapi\Reader;
use cebe\openapi\ReferenceContext;
use Ingot\SchemaGen\SchemaGenerator;
use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/../vendor/autoload.php';

const GENERATED_NOTE = 'Generated from ../openapi.yaml by tools/build-docs.php — do not edit by hand; run `make docs`.';

/** The marker a schema carries instead of a hand-written shape. */
const SCHEMA_MARKER = 'x-ingot-schema';

/**
 * Annotations that may sit next to the marker: prose and client hints a
 * generator cannot know. Anything else would compete with the DTO for
 * ownership of the shape, so it is an error.
 */
const MARKER_ANNOTATIONS = ['description', 'title', 'default', 'example', 'examples', 'deprecated'];

/** HTTP methods in the order operations get documented, whatever order the source uses. */
const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

function main(): int
{
    $root = \dirname(__DIR__);
    $source = $root . '/openapi.yaml';
    $docs = $root . '/docs';
    $contract = $docs . '/openapi.yaml';

    $raw = Yaml::parseFile($source);

    if (!\is_array($raw)) {
        fwrite(\STDERR, "openapi.yaml does not contain a YAML mapping\n");

        return 1;
    }

    if (!is_dir($docs) && !mkdir($docs, 0o775, true) && !is_dir($docs)) {
        fwrite(\STDERR, \sprintf("Cannot create %s\n", $docs));

        return 1;
    }

    /** @var array<string, mixed> $raw */
    try {
        $document = injectGeneratedSchemas($raw);
    } catch (\RuntimeException $exception) {
        fwrite(\STDERR, $exception->getMessage() . "\n");

        return 1;
    }

    write($contract, '# ' . GENERATED_NOTE . "\n" . Yaml::dump($document, 12, 2));
    write($docs . '/api.md', renderMarkdown($document));

    // The generated contract is what clients and the contract tests read, so
    // validate the output, not just the input.
    $spec = Reader::readFromYamlFile($contract, resolveReferences: ReferenceContext::RESOLVE_MODE_INLINE);

    if (!$spec->validate()) {
        fwrite(\STDERR, "docs/openapi.yaml is not a valid OpenAPI document:\n  " . implode("\n  ", $spec->getErrors()) . "\n");

        return 1;
    }

    fwrite(\STDOUT, "Wrote docs/openapi.yaml and docs/api.md\n");

    return 0;
}

/**
 * Replaces every `x-ingot-schema` marker with the schema ingot generates from
 * the named request DTO (optionally one of its properties, `Class#property`).
 *
 * @param array<string, mixed> $node
 *
 * @return array<string, mixed>
 */
function injectGeneratedSchemas(array $node): array
{
    $marker = $node[SCHEMA_MARKER] ?? null;

    if (\is_string($marker)) {
        return generatedSchema($marker) + annotationsOf($node, $marker);
    }

    foreach ($node as $key => $value) {
        if (\is_array($value)) {
            /** @var array<string, mixed> $value */
            $node[$key] = injectGeneratedSchemas($value);
        }
    }

    return $node;
}

/**
 * @param array<string, mixed> $node
 *
 * @return array<string, mixed>
 */
function annotationsOf(array $node, string $marker): array
{
    $annotations = [];

    foreach ($node as $key => $value) {
        if ($key === SCHEMA_MARKER) {
            continue;
        }

        if (!\in_array($key, MARKER_ANNOTATIONS, true)) {
            throw new \RuntimeException(\sprintf(
                'openapi.yaml: "%s" sits next to %s "%s", but the DTO owns the shape — only %s may be added by hand.',
                $key,
                SCHEMA_MARKER,
                $marker,
                implode(', ', MARKER_ANNOTATIONS),
            ));
        }

        $annotations[$key] = $value;
    }

    return $annotations;
}

/**
 * The generated schema of a DTO, or of one of its properties when the marker
 * is written as `Class#property`.
 *
 * @return array<string, mixed>
 */
function generatedSchema(string $marker): array
{
    $parts = explode('#', $marker, 2);
    $class = $parts[0];
    $property = $parts[1] ?? null;

    if (!class_exists($class)) {
        throw new \RuntimeException(\sprintf('openapi.yaml: %s "%s" names an unknown class.', SCHEMA_MARKER, $marker));
    }

    $document = new SchemaGenerator()->generate($class)->document;
    $decoded = json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

    if (!\is_array($decoded)) {
        throw new \RuntimeException(\sprintf('%s did not generate an object schema.', $class));
    }

    /** @var array<string, mixed> $decoded */
    $schema = rootDefinitionOf($decoded, $class);

    if ($property === null) {
        return $schema;
    }

    $properties = objectAt($schema, 'properties');

    if (!\array_key_exists($property, $properties) || !\is_array($properties[$property])) {
        throw new \RuntimeException(\sprintf('openapi.yaml: %s has no property "%s".', $class, $property));
    }

    /** @var array<string, mixed> */
    return $properties[$property];
}

/**
 * SchemaGenerator emits `{$schema, $ref: '#/$defs/Name', $defs: {...}}`. An
 * OpenAPI component wants the shape itself, so unwrap the single definition —
 * and refuse to guess when a DTO drags nested classes along (none does today;
 * if one appears, its $defs need a deliberate home in the document).
 *
 * @param array<string, mixed> $document
 *
 * @return array<string, mixed>
 */
function rootDefinitionOf(array $document, string $class): array
{
    $defs = objectAt($document, '$defs');

    if (\count($defs) !== 1) {
        throw new \RuntimeException(\sprintf(
            'The schema generated for %s has %d $defs entries; only a self-contained DTO can be inlined into the document.',
            $class,
            \count($defs),
        ));
    }

    $definition = reset($defs);

    if (!\is_array($definition)) {
        throw new \RuntimeException(\sprintf('%s did not generate an object schema.', $class));
    }

    /** @var array<string, mixed> */
    return $definition;
}

function write(string $file, string $content): void
{
    if (file_put_contents($file, $content) === false) {
        throw new \RuntimeException(\sprintf('Cannot write %s', $file));
    }
}

/**
 * @param array<string, mixed> $raw
 */
function renderMarkdown(array $raw): string
{
    $info = objectAt($raw, 'info');
    $paths = objectAt($raw, 'paths');

    $lines = [
        '<!-- ' . GENERATED_NOTE . ' -->',
        '',
        \sprintf('# %s %s', stringAt($info, 'title'), stringAt($info, 'version')),
        '',
        paragraph(stringAt($info, 'description')),
        '',
        'Machine-readable contract: [`openapi.yaml`](openapi.yaml) — this document with every',
        '`$ref` inlined. Both halves of every exchange listed here (request *and* response) are',
        'asserted against real HTTP traffic by `tests/Http/OpenApiComplianceTest.php`, so this',
        'reference cannot drift from the implementation.',
        '',
        '## Endpoints',
        '',
        '| Method & path | Operation | Purpose | Responses |',
        '|---|---|---|---|',
    ];

    foreach (operations($paths) as [$method, $path, $operation]) {
        $statuses = array_map(
            static fn(int|string $status): string => \sprintf('`%s`', $status),
            array_keys(objectAt($operation, 'responses')),
        );

        $lines[] = \sprintf(
            '| [`%s %s`](#%s) | `%s` | %s | %s |',
            $method,
            $path,
            anchor(\sprintf('%s %s', $method, $path)),
            stringAt($operation, 'operationId'),
            cell(stringAt($operation, 'summary')),
            implode(', ', $statuses),
        );
    }

    $lines[] = '';
    $lines[] = '## Operations';

    foreach (operations($paths) as [$method, $path, $operation]) {
        array_push($lines, '', ...renderOperation($method, $path, $operation, objectAt($paths, $path), $raw));
    }

    $lines[] = '';
    $lines[] = '## Schemas';

    foreach (objectAt(objectAt($raw, 'components'), 'schemas') as $name => $schema) {
        if (!\is_array($schema)) {
            continue;
        }

        /** @var array<string, mixed> $schema */
        array_push($lines, '', ...renderSchema($name, $schema));
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param array<string, mixed> $paths
 *
 * @return list<array{string, string, array<string, mixed>}> method, path, operation
 */
function operations(array $paths): array
{
    $operations = [];

    foreach ($paths as $path => $pathItem) {
        if (!\is_array($pathItem)) {
            continue;
        }

        foreach (METHODS as $method) {
            $operation = $pathItem[$method] ?? null;

            if (\is_array($operation)) {
                /** @var array<string, mixed> $operation */
                $operations[] = [strtoupper($method), $path, $operation];
            }
        }
    }

    return $operations;
}

/**
 * @param array<string, mixed> $operation
 * @param array<string, mixed> $pathItem shared path-level parameters live here
 * @param array<string, mixed> $root     the whole document — reusable parameters and
 *                                       responses are referenced, not spelled out
 *
 * @return list<string>
 */
function renderOperation(string $method, string $path, array $operation, array $pathItem, array $root): array
{
    $lines = [
        \sprintf('### %s %s', $method, $path),
        '',
        \sprintf('`operationId: %s` — %s', stringAt($operation, 'operationId'), stringAt($operation, 'summary')),
    ];

    $description = stringAt($operation, 'description');

    if ($description !== '') {
        array_push($lines, '', paragraph($description));
    }

    $parameters = [...objectListAt($pathItem, 'parameters'), ...objectListAt($operation, 'parameters')];

    if ($parameters !== []) {
        array_push($lines, '', '**Parameters**', '', '| Name | In | Required | Type | Description |', '|---|---|---|---|---|');

        foreach ($parameters as $parameter) {
            $parameter = deref($parameter, $root);
            $lines[] = \sprintf(
                '| `%s` | %s | %s | %s | %s |',
                stringAt($parameter, 'name'),
                stringAt($parameter, 'in'),
                yesNo(($parameter['required'] ?? null) === true),
                type(objectAt($parameter, 'schema')),
                cell(stringAt($parameter, 'description')),
            );
        }
    }

    $body = deref(objectAt($operation, 'requestBody'), $root);

    foreach (objectAt($body, 'content') as $mediaType => $media) {
        if (!\is_array($media)) {
            continue;
        }

        /** @var array<string, mixed> $media */
        array_push($lines, '', \sprintf(
            '**Request body** (`%s`%s): %s',
            $mediaType,
            ($body['required'] ?? null) === true ? ', required' : '',
            type(objectAt($media, 'schema')),
        ));
    }

    array_push($lines, '', '**Responses**', '', '| Status | Content type | Body | Description |', '|---|---|---|---|');

    foreach (objectAt($operation, 'responses') as $status => $documented) {
        if (!\is_array($documented)) {
            continue;
        }

        /** @var array<string, mixed> $documented */
        $response = deref($documented, $root);
        $content = objectAt($response, 'content');
        $description = cell(stringAt($response, 'description'));

        if ($content === []) {
            $lines[] = \sprintf('| `%s` | — | empty | %s |', $status, $description);

            continue;
        }

        foreach ($content as $mediaType => $media) {
            if (!\is_array($media)) {
                continue;
            }

            /** @var array<string, mixed> $media */
            $lines[] = \sprintf(
                '| `%s` | `%s` | %s | %s |',
                $status,
                $mediaType,
                type(objectAt($media, 'schema')),
                $description,
            );
        }
    }

    return $lines;
}

/**
 * @param array<string, mixed> $schema
 *
 * @return list<string>
 */
function renderSchema(string $name, array $schema): array
{
    $lines = [\sprintf('### %s', $name)];
    $description = stringAt($schema, 'description');

    if ($description !== '') {
        array_push($lines, '', paragraph($description));
    }

    $properties = objectAt($schema, 'properties');

    if ($properties === []) {
        array_push($lines, '', \sprintf('Type: %s', type($schema)));

        return $lines;
    }

    $required = scalarListAt($schema, 'required');
    array_push($lines, '', '| Property | Type | Required | Description |', '|---|---|---|---|');

    foreach ($properties as $property => $definition) {
        if (!\is_array($definition)) {
            continue;
        }

        /** @var array<string, mixed> $definition */
        $lines[] = \sprintf(
            '| `%s` | %s | %s | %s |',
            $property,
            type($definition),
            yesNo(\in_array($property, $required, true)),
            cell(stringAt($definition, 'description')),
        );
    }

    if (($schema['additionalProperties'] ?? null) === false) {
        array_push($lines, '', 'No other properties are allowed.');
    }

    return $lines;
}

/**
 * Follows a local `$ref` (reusable parameters and responses) one hop. Schema
 * references are deliberately left alone — {@see type()} turns those into links
 * to the Schemas section instead of inlining them.
 *
 * @param array<string, mixed> $node
 * @param array<string, mixed> $root
 *
 * @return array<string, mixed>
 */
function deref(array $node, array $root): array
{
    $reference = $node['$ref'] ?? null;

    if (!\is_string($reference) || !str_starts_with($reference, '#/')) {
        return $node;
    }

    $target = $root;

    foreach (explode('/', substr($reference, 2)) as $segment) {
        $target = objectAt($target, $segment);
    }

    return $target;
}

/**
 * A human-readable type for a schema. References become links to the matching
 * section, so the reference stays navigable.
 *
 * @param array<string, mixed> $schema
 */
function type(array $schema): string
{
    $reference = $schema['$ref'] ?? null;

    if (\is_string($reference)) {
        $name = substr($reference, (int) strrpos($reference, '/') + 1);

        return \sprintf('[`%s`](#%s)', $name, anchor($name));
    }

    $alternatives = objectListAt($schema, 'oneOf');

    if ($alternatives !== []) {
        return implode(' or ', array_map(type(...), $alternatives));
    }

    $names = scalarListAt($schema, 'type');
    // "\|" keeps a union from being read as a table column separator.
    $rendered = $names === [] ? 'any' : implode(' \| ', $names);

    if ($names === ['array']) {
        $items = objectAt($schema, 'items');

        return $items === [] ? '`array`' : \sprintf('`array` of %s', type($items));
    }

    $enum = scalarListAt($schema, 'enum');

    if ($enum !== []) {
        return \sprintf('`%s` (`%s`)', $rendered, implode('` \| `', $enum));
    }

    $qualifiers = [];
    $format = stringAt($schema, 'format');

    if ($format !== '') {
        $qualifiers[] = '`' . $format . '`';
    }

    $qualifiers = [...$qualifiers, ...bounds($schema)];

    return $qualifiers === []
        ? \sprintf('`%s`', $rendered)
        : \sprintf('`%s` (%s)', $rendered, implode(', ', $qualifiers));
}

/**
 * The validation keywords worth showing next to a type — a reader of the
 * reference should not have to open the schema to learn the accepted range.
 *
 * @param array<string, mixed> $schema
 *
 * @return list<string>
 */
function bounds(array $schema): array
{
    $templates = [
        'minimum' => '≥ %s',
        'maximum' => '≤ %s',
        'exclusiveMinimum' => '> %s',
        'exclusiveMaximum' => '< %s',
        'minLength' => 'min length %s',
        'maxLength' => 'max length %s',
        'minItems' => 'min %s items',
        'maxItems' => 'max %s items',
    ];

    $bounds = [];

    foreach ($templates as $keyword => $template) {
        $value = $schema[$keyword] ?? null;

        if (\is_int($value) || \is_float($value)) {
            $bounds[] = \sprintf($template, $value);
        }
    }

    if (($schema['uniqueItems'] ?? null) === true) {
        $bounds[] = 'unique';
    }

    if (\is_string($schema['pattern'] ?? null)) {
        $bounds[] = \sprintf('pattern `%s`', $schema['pattern']);
    }

    return $bounds;
}

/**
 * @param array<string, mixed> $data
 *
 * @return array<string, mixed>
 */
function objectAt(array $data, string $key): array
{
    $value = $data[$key] ?? null;

    if (!\is_array($value)) {
        return [];
    }

    /** @var array<string, mixed> */
    return $value;
}

/**
 * @param array<string, mixed> $data
 *
 * @return list<array<string, mixed>>
 */
function objectListAt(array $data, string $key): array
{
    $items = [];

    foreach (objectAt($data, $key) as $item) {
        if (\is_array($item)) {
            /** @var array<string, mixed> $item */
            $items[] = $item;
        }
    }

    return $items;
}

/**
 * A scalar or list of scalars (`type`, `required`, `enum`) as a list of strings.
 *
 * @param array<string, mixed> $data
 *
 * @return list<string>
 */
function scalarListAt(array $data, string $key): array
{
    $value = $data[$key] ?? null;

    if (\is_scalar($value)) {
        return [scalarToString($value)];
    }

    if (!\is_array($value)) {
        return [];
    }

    $items = [];

    foreach ($value as $item) {
        if (\is_scalar($item)) {
            $items[] = scalarToString($item);
        }
    }

    return $items;
}

function scalarToString(bool|float|int|string $value): string
{
    return match (true) {
        \is_bool($value) => $value ? 'true' : 'false',
        default => (string) $value,
    };
}

/**
 * @param array<string, mixed> $data
 */
function stringAt(array $data, string $key): string
{
    $value = $data[$key] ?? null;

    return \is_string($value) ? $value : '';
}

function yesNo(bool $value): string
{
    return $value ? 'yes' : 'no';
}

/** The GitHub anchor of a heading: lowercase, punctuation dropped, spaces to dashes. */
function anchor(string $heading): string
{
    $slug = preg_replace('/[^a-z0-9 -]/', '', strtolower($heading));

    return str_replace(' ', '-', (string) $slug);
}

/** Collapses a folded YAML description into one paragraph. */
function paragraph(string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', $text));
}

/** Same, plus escaping the one character that would break a table row. */
function cell(string $text): string
{
    return str_replace('|', '\|', paragraph($text));
}

exit(main());
