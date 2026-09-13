<?php

declare(strict_types=1);

namespace App\Tests\Browser\Printing;

use Facebook\WebDriver\WebDriverBy;

/**
 * And in the richer one, where the sheet is in bootstrap-form.css and has a skin's literal colours to overrule.
 */
final class BootstrapPrintedPageTest extends PrintedPageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }

    /**
     * The one control whose answer lived in a **fill**, and a fill is what a
     * printer is free to leave out.
     *
     * `print-color-adjust` is `economy` by default. Bootstrap sets `exact` on a
     * checkbox and not on a button, so a picked toggle — white text on a
     * background nobody printed — came out white on white, while the two nobody
     * picked stayed perfectly readable. The answer was the one thing missing
     * from the page, which is worse than a page that is merely hard to read.
     *
     * Only this kit has the problem, and structurally so: the plain one leaves
     * its controls native (`appearance: auto`), so the browser draws the mark
     * and the browser prints it.
     */
    public function testThePickedOptionIsTheOneThatReadsAsPickedOnPaper(): void
    {
        // GIVEN a form answered with the second of three choices
        $id = $this->plantChoice('b');
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN the page is laid out for paper
        $this->onPaper();

        // THEN the picked option is said in ink — black, bold, in a black frame —
        // and not in a colour a printer may decide not to lay down
        self::assertSame('rgb(0, 0, 0)', $this->styleOf('.btn-check:checked + .btn', 'color'));
        self::assertSame('700', $this->styleOf('.btn-check:checked + .btn', 'font-weight'));
        self::assertSame('rgb(0, 0, 0)', $this->styleOf('.btn-check:checked + .btn', 'border-top-color'));

        // AND the ones nobody picked are plainly not it
        self::assertSame('rgb(85, 85, 85)', $this->styleOf('.btn-check:not(:checked) + .btn', 'color'));
        self::assertSame('400', $this->styleOf('.btn-check:not(:checked) + .btn', 'font-weight'));
    }

    /**
     * A slider's answer is where the thumb sits, which is the one answer nobody
     * can read: not at any precision worth having on a screen, and not at all on
     * paper, where the track and the thumb are backgrounds a printer may leave
     * out. So the number is written beside it — and on paper the number is what
     * stays, because a bar that prints as an empty gap says less than nothing.
     */
    public function testASliderCarriesItsAnswerAsANumber(): void
    {
        // GIVEN a form whose slider was answered
        $id = $this->plantRange(7);
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN the number is beside it on screen
        self::assertSame('7', $this->textOf('.range-value'));

        // WHEN somebody moves the slider
        $this->browser->executeScript(<<<'JS'
                        const slider = document.querySelector('input[type=range]');
                        slider.value = '9';
                        slider.dispatchEvent(new Event('input', {bubbles: true}));
            JS);

        // THEN the number follows it
        self::assertSame('9', $this->eventually(fn(): ?string => $this->textOf('.range-value') === '9' ? '9' : null));

        // WHEN the page is laid out for paper
        $this->onPaper();

        // THEN the number is on it and the bar is not
        self::assertSame(1, $this->shown('.range-value'));
        self::assertSame(0, $this->shown('.form-range'));
    }

    /** A form asking one closed question, drawn as a group of toggles. */
    private function plantChoice(string $answer): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'select', 'name' => 'rodzaj', 'options' => ['a', 'b', 'c'], 'required' => true],
                ]],
                'data' => ['rodzaj' => $answer],
                'presentation' => [
                    'engine' => 'bootstrap',
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'rodzaj', 'widget' => 'radio-buttons', 'label' => 't.kind'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => ['t.kind' => 'Kind', 't.send' => 'Send it']],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        return $this->planted($body['id']);
    }

    /** A form asking for one number, drawn as a slider. */
    private function plantRange(int $answer): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'number', 'name' => 'ocena', 'min' => 0, 'max' => 10, 'decimals' => 0],
                ]],
                'data' => ['ocena' => $answer],
                'presentation' => [
                    'engine' => 'bootstrap',
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'ocena', 'widget' => 'range', 'label' => 't.rating'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => ['t.rating' => 'Rating', 't.send' => 'Send it']],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        return $this->planted($body['id']);
    }

    /**
     * What the page says there now.
     *
     * `@phpstan-impure` because it is: the browser is the state, so asking twice
     * is allowed to answer twice — which is the whole point of asking again
     * after something moved.
     *
     * @phpstan-impure
     */
    private function textOf(string $selector): string
    {
        return trim($this->browser->findElement(WebDriverBy::cssSelector($selector))->getText());
    }
}
