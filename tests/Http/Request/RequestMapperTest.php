<?php

declare(strict_types=1);

namespace App\Tests\Http\Request;

use App\Domain\Forms\DeriveMode;
use App\Http\Request\CreateFormRequest;
use App\Http\Request\DataSchemaQuery;
use App\Http\Request\FormListQuery;
use App\Http\Request\FutureExpireDate;
use App\Http\Request\RequestMapper;
use App\Http\Request\RequestNotValid;
use Ingot\Error\ErrorReport;
use PHPUnit\Framework\TestCase;

/**
 * The request DTOs are the request contract: these tests pin what the mapper
 * accepts and how it reports the rest, because the very same declarations are
 * published as the API's request schemas.
 */
final class RequestMapperTest extends TestCase
{
    private const string BODY = '{"expireDate": "2999-01-01T10:00:00+00:00", "definition": {"id": "contact"}}';

    public function testMapsAValidBodyIntoTheDto(): void
    {
        // GIVEN
        $mapper = self::mapper();

        // WHEN
        $request = $mapper->fromBody(CreateFormRequest::class, self::BODY);

        // THEN the date became a real object and the definition stayed raw
        self::assertSame('2999-01-01T10:00:00+00:00', $request->expireDate->format(\DateTimeInterface::ATOM));
        self::assertSame(['id' => 'contact'], $request->definition);
    }

    public function testBodyKeysAreRequired(): void
    {
        // GIVEN
        $mapper = self::mapper();

        // WHEN
        $report = self::reportOf(static fn(): object => $mapper->fromBody(CreateFormRequest::class, '{}'));

        // THEN both missing members are reported in one pass
        self::assertSame(['mapping.missing_key', 'mapping.missing_key'], self::codes($report));
    }

    public function testBodyRejectsAnUnexpectedKey(): void
    {
        // GIVEN a body carrying a member the DTO does not declare
        $mapper = self::mapper();
        $json = '{"expireDate": "2999-01-01T10:00:00+00:00", "definition": {}, "bogus": 1}';

        // WHEN
        $report = self::reportOf(static fn(): object => $mapper->fromBody(CreateFormRequest::class, $json));

        // THEN the closed contract names the offending pointer
        self::assertSame('/bogus', $report->errors[0]->pointer->toString());
        self::assertSame('mapping.unexpected_key', $report->errors[0]->code);
    }

    public function testExpireDateMustBeRfc3339(): void
    {
        // GIVEN
        $mapper = self::mapper();
        $json = '{"expireDate": "tomorrow", "definition": {}}';

        // WHEN
        $report = self::reportOf(static fn(): object => $mapper->fromBody(CreateFormRequest::class, $json));

        // THEN PHP's lenient date parsing does not get a say
        self::assertSame('/expireDate', $report->errors[0]->pointer->toString());
        self::assertSame('mapping.format', $report->errors[0]->code);
    }

    public function testExpireDateMustBeInTheFuture(): void
    {
        // GIVEN a syntactically perfect date that has already passed
        $mapper = self::mapper();
        $json = json_encode([
            'expireDate' => new \DateTimeImmutable('-1 hour')->format(\DateTimeInterface::ATOM),
            'definition' => [],
        ], \JSON_THROW_ON_ERROR);

        // WHEN
        $report = self::reportOf(static fn(): object => $mapper->fromBody(CreateFormRequest::class, $json));

        // THEN the semantic rule reports it where the value sat
        self::assertSame('/expireDate', $report->errors[0]->pointer->toString());
        self::assertSame('form.expire_date.past', $report->errors[0]->code);
    }

    public function testMalformedBodyIsASourceError(): void
    {
        // GIVEN
        $mapper = self::mapper();

        // WHEN
        $report = self::reportOf(static fn(): object => $mapper->fromBody(CreateFormRequest::class, '{broken'));

        // THEN — the listener turns exactly this report into a 400
        self::assertSame(['source.malformed_json'], self::codes($report));
    }

    public function testQueryStringsAreCoercedToTheDeclaredTypes(): void
    {
        // GIVEN query values, which are always strings on the wire
        $mapper = self::mapper();

        // WHEN
        $query = $mapper->fromQuery(FormListQuery::class, ['limit' => '10', 'offset' => '20']);

        // THEN
        self::assertSame(10, $query->limit);
        self::assertSame(20, $query->offset);
    }

    public function testQueryFallsBackToTheDeclaredDefaults(): void
    {
        // GIVEN no query parameters at all
        $mapper = self::mapper();

        // WHEN
        $query = $mapper->fromQuery(FormListQuery::class, []);

        // THEN
        self::assertSame(50, $query->limit);
        self::assertSame(0, $query->offset);
    }

    public function testQueryIgnoresUnknownParameters(): void
    {
        // GIVEN a tracking parameter nobody declared
        $mapper = self::mapper();

        // WHEN
        $query = $mapper->fromQuery(FormListQuery::class, ['limit' => '10', 'utm_source' => 'newsletter']);

        // THEN it is ignored — unlike in a body, extras are normal here
        self::assertSame(10, $query->limit);
    }

    public function testQueryEnforcesThePagingRange(): void
    {
        // GIVEN a page size past the documented maximum
        $mapper = self::mapper();

        // WHEN
        $report = self::reportOf(static fn(): object => $mapper->fromQuery(FormListQuery::class, ['limit' => '500']));

        // THEN it is rejected, not silently clamped
        self::assertSame('/limit', $report->errors[0]->pointer->toString());
        self::assertSame('mapping.maximum', $report->errors[0]->code);
    }

    public function testSchemaModeMapsIntoTheDomainEnum(): void
    {
        // GIVEN
        $mapper = self::mapper();

        // WHEN
        $query = $mapper->fromQuery(DataSchemaQuery::class, ['mode' => 'draft']);

        // THEN
        self::assertSame(DeriveMode::Draft, $query->mode);
    }

    public function testUnknownSchemaModeIsReportedAtItsPointer(): void
    {
        // GIVEN
        $mapper = self::mapper();

        // WHEN
        $report = self::reportOf(static fn(): object => $mapper->fromQuery(DataSchemaQuery::class, ['mode' => 'bogus']));

        // THEN
        self::assertSame('/mode', $report->errors[0]->pointer->toString());
        self::assertSame('mapping.enum', $report->errors[0]->code);
    }

    private static function mapper(): RequestMapper
    {
        return new RequestMapper([new FutureExpireDate()]);
    }

    /**
     * @param \Closure(): object $mapping
     */
    private static function reportOf(\Closure $mapping): ErrorReport
    {
        try {
            $mapping();
        } catch (RequestNotValid $exception) {
            return $exception->report;
        }

        self::fail('Expected RequestNotValid.');
    }

    /**
     * @return list<string>
     */
    private static function codes(ErrorReport $report): array
    {
        $codes = [];

        foreach ($report as $error) {
            $codes[] = $error->code;
        }

        return $codes;
    }
}
