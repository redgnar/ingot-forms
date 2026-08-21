<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Application\Forms\File\IncomingFile;
use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;

/**
 * What a file item accepts as a value: the description an upload answered with,
 * and nothing else.
 *
 * Two kinds of row live in this table, and the difference is the point. Most of
 * them are refused by the **published schema** — a size over the ceiling, a kind
 * of bytes the item does not want, a member nobody declared — because that is
 * where a file item's rules are stated. The last few are refused by the
 * **reference gate**, which asks the store whether the file is really there and
 * described that way: the one thing no schema can say, and the one place this
 * server is stricter than its own contract.
 *
 * So the files this test claims are really in the store, put there the way an
 * upload puts them.
 */
final class FileFieldValuesTest extends FieldValuesTestCase
{
    private const string A_PDF = '01a0f3d4-0000-7000-8000-0000000000a1';

    private const string A_PNG = '01a0f3d4-0000-7000-8000-0000000000a2';

    private const string NEVER_UPLOADED = '01a0f3d4-0000-7000-8000-0000000000ff';

    private const string PDF_BYTES = '%PDF-1.4 a tiny invoice';

    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAAMBAQAY3Y2wAAAAAElFTkSuQmCC';

    private FormId $form;

    private FileStore $store;

    /** @var list<string> */
    private array $temporary = [];

    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'file', 'name' => 'invoice', 'required' => true, 'accept' => ['application/pdf'], 'maxSize' => 1024],
            // An item that takes either kind, which is the only way to reach the
            // gate's own opinion about what the bytes are: as long as one item
            // accepts one type, the schema settles the question first.
            ['type' => 'file', 'name' => 'photo', 'accept' => ['application/pdf', 'image/png'], 'maxSize' => 1024],
            ['type' => 'collection', 'name' => 'attachments', 'max' => 2, 'items' => [
                ['type' => 'text', 'name' => 'caption'],
                ['type' => 'file', 'name' => 'scan', 'accept' => ['image/png'], 'maxSize' => 1024],
            ]],
        ]];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $store = self::getContainer()->get(FileStore::class);
        self::assertInstanceOf(FileStore::class, $store);
        $this->store = $store;
        $this->form = FormId::next();

        // What an upload would have left behind. The bytes matter: the store
        // measures them, and the table below claims exactly what it measured.
        $this->store->put($this->form, FileId::fromString(self::A_PDF), $this->upload('invoice.pdf', self::PDF_BYTES));
        $this->store->put($this->form, FileId::fromString(self::A_PNG), $this->upload('scan.png', self::png()));
    }

    protected function tearDown(): void
    {
        $this->store->forget($this->form);

        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    protected function formId(): FormId
    {
        return $this->form;
    }

    public static function verdicts(): iterable
    {
        $pdf = self::reference(self::A_PDF, 'invoice.pdf', \strlen(self::PDF_BYTES), 'application/pdf');
        $png = self::reference(self::A_PNG, 'scan.png', \strlen(self::png()), 'image/png');

        // What a filled-in file item looks like, in both contracts.
        yield 'the file that was uploaded' => [DeriveMode::Strict, '{"invoice": ' . $pdf . '}', null, null];
        yield 'the same, while filling in' => [DeriveMode::Draft, '{"invoice": ' . $pdf . '}', null, null];
        yield 'nothing attached yet' => [DeriveMode::Draft, '{}', null, null];
        yield 'a file inside an entry' => [
            DeriveMode::Draft,
            '{"invoice": ' . $pdf . ', "attachments": [{"caption": "the front", "scan": ' . $png . '}]}',
            null,
            null,
        ];

        // Confirmation wants the file the form asked for.
        yield 'confirmation wants the file' => [DeriveMode::Strict, '{}', '/invoice', 'schema.required'];

        // The description arrives whole from one response, so half of one is a
        // client mistake in both contracts — not an answer somebody has not got
        // round to.
        yield 'half a description' => [
            DeriveMode::Draft,
            '{"invoice": {"id": "' . self::A_PDF . '", "name": "invoice.pdf", "type": "application/pdf"}}',
            '/invoice/size',
            'schema.required',
        ];
        yield 'a description that is not one' => [DeriveMode::Draft, '{"invoice": "' . self::A_PDF . '"}', '/invoice', 'schema.type'];
        yield 'an id that is not an id' => [
            DeriveMode::Draft,
            '{"invoice": ' . self::reference('the-invoice', 'invoice.pdf', 23, 'application/pdf') . '}',
            '/invoice/id',
            'schema.format',
        ];
        yield 'a member the description does not have' => [
            DeriveMode::Draft,
            '{"invoice": {"id": "' . self::A_PDF . '", "name": "invoice.pdf", "size": 23, "type": "application/pdf", "colour": "red"}}',
            '/invoice/colour',
            'schema.additionalProperties',
        ];

        // The item's own two rules, said in the published schema and enforced
        // there — which is the whole reason the description travels in the values.
        yield 'a file over the ceiling' => [
            DeriveMode::Draft,
            '{"invoice": ' . self::reference(self::A_PDF, 'invoice.pdf', 99999, 'application/pdf') . '}',
            '/invoice/size',
            'schema.maximum',
        ];
        yield 'a kind of bytes this item does not want' => [
            DeriveMode::Draft,
            '{"invoice": ' . self::reference(self::A_PDF, 'invoice.pdf', 23, 'text/plain') . '}',
            '/invoice/type',
            'schema.enum',
        ];
        // A real file, in the wrong place: the collection accepts images, the
        // invoice does not, and the schema says so before anything is asked of
        // the store.
        yield 'a real file of the wrong kind' => [
            DeriveMode::Draft,
            '{"invoice": ' . $png . '}',
            '/invoice/type',
            'schema.enum',
        ];
        yield 'a name that is a path' => [
            DeriveMode::Draft,
            '{"invoice": ' . self::reference(self::A_PDF, 'dir/invoice.pdf', 23, 'application/pdf') . '}',
            '/invoice/name',
            'schema.pattern',
        ];
        yield 'a file of no bytes' => [
            DeriveMode::Draft,
            '{"invoice": ' . self::reference(self::A_PDF, 'invoice.pdf', 0, 'application/pdf') . '}',
            '/invoice/size',
            'schema.minimum',
        ];

        // ...and past all of that, the gate: a description the schema is happy
        // with, of a file that is not there or is not that.
        yield 'a file nobody uploaded' => [
            DeriveMode::Draft,
            '{"invoice": ' . self::reference(self::NEVER_UPLOADED, 'invoice.pdf', 23, 'application/pdf') . '}',
            '/invoice/id',
            'form.file.unknown',
        ];
        yield 'a size the store did not measure' => [
            DeriveMode::Draft,
            '{"invoice": ' . self::reference(self::A_PDF, 'invoice.pdf', 22, 'application/pdf') . '}',
            '/invoice/size',
            'form.file.mismatch',
        ];
        yield 'a name the store did not record' => [
            DeriveMode::Draft,
            '{"invoice": ' . self::reference(self::A_PDF, 'INVOICE.pdf', \strlen(self::PDF_BYTES), 'application/pdf') . '}',
            '/invoice/name',
            'form.file.mismatch',
        ];
        // Both kinds are in this item's `accept`, so the schema lets the claim
        // through — and only the store knows which one those bytes really are.
        yield 'a kind of bytes the store did not sniff' => [
            DeriveMode::Draft,
            '{"photo": ' . self::reference(self::A_PDF, 'invoice.pdf', \strlen(self::PDF_BYTES), 'image/png') . '}',
            '/photo/type',
            'form.file.mismatch',
        ];
        // The gate asks the same question one scope down, and points there.
        yield 'a file nobody uploaded, inside an entry' => [
            DeriveMode::Draft,
            '{"attachments": [{"scan": ' . self::reference(self::NEVER_UPLOADED, 'scan.png', 70, 'image/png') . '}]}',
            '/attachments/0/scan/id',
            'form.file.unknown',
        ];
        // And a file of this form is not a file of another item's list: the pair
        // is what addresses bytes, so an id from the same form is fine wherever
        // the schema allows its kind.
        yield 'the same file named twice' => [
            DeriveMode::Draft,
            '{"invoice": ' . $pdf . ', "attachments": [{"scan": ' . $png . '}, {"scan": ' . $png . '}]}',
            null,
            null,
        ];
    }

    private static function reference(string $id, string $name, int $size, string $type): string
    {
        return json_encode(['id' => $id, 'name' => $name, 'size' => $size, 'type' => $type], \JSON_THROW_ON_ERROR);
    }

    private static function png(): string
    {
        $bytes = base64_decode(self::PNG_BASE64, true);
        self::assertIsString($bytes);

        return $bytes;
    }

    private function upload(string $clientName, string $bytes): IncomingFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ingot-values');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return new IncomingFile($path, $clientName);
    }
}
