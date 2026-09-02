<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Symfony\Component\HttpClient\HttpClient;

/**
 * A browser test takes its fixtures away again.
 *
 * Every other suite gets this for free: they run against the kernel, and
 * dama/doctrine-test-bundle wraps each test in a transaction it rolls back. A
 * browser test cannot — it talks to a **separate server process**, whose writes
 * are committed by somebody else's connection, which is the whole reason a
 * fixture is created over HTTP in the first place ({@see \App\Tests\Browser\FormPageTest}).
 * So nothing rolled back, and every run left its forms in the database for ever:
 * four thousand of them by the time anybody looked, which is slow to read, slow
 * to purge, and noise in front of anybody trying to see real data.
 *
 * The cleanup goes through the API for the same reason the fixture did. Deleting
 * through the container would be undone: that connection is the one inside the
 * transaction. And it is `DELETE /api/manage/forms/{id}`, not SQL, so a fixture
 * leaves the way a form leaves — its revisions by foreign key, its announcements
 * with them, its uploaded bytes after the row.
 *
 * Failures are ignored on purpose. A test is allowed to delete its own form (one
 * of them checks exactly that), and a cleanup that failed the suite would report
 * a problem that is not there.
 */
trait DeletesWhatItPlanted
{
    /** @var list<string> the forms this test created, in the order it made them */
    private array $planted = [];

    protected function tearDown(): void
    {
        $this->deletePlantedForms();

        parent::tearDown();
    }

    /**
     * The cleanup itself, named so a case with a `tearDown()` of its own can call
     * it — a class's method beats a trait's, and silently, which is how the file
     * suite went on leaking after the rest had stopped.
     */
    final protected function deletePlantedForms(): void
    {
        // The server this suite drives is still up while tests run, and its base
        // address is what the fixtures were created against.
        $api = HttpClient::create(['base_uri' => self::$baseUri]);

        foreach ($this->planted as $id) {
            try {
                $api->request('DELETE', \sprintf('/api/manage/forms/%s', $id))->getStatusCode();
            } catch (\Throwable) {
                // Already gone, or the server has stopped: either way there is
                // nothing here worth failing a green test over.
            }
        }

        $this->planted = [];
    }

    /**
     * Records a form this test created, and hands the id straight back so a
     * fixture reads as it did before: `return $this->planted($body['id'])`.
     */
    final protected function planted(string $id): string
    {
        $this->planted[] = $id;

        return $id;
    }
}
