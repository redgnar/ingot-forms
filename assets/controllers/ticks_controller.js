import { Controller } from '@hotwired/stimulus';

/**
 * Keeps a group of ticks under its ceiling.
 *
 * Every other maximum in this kit is in the markup — `maxlength` on a text box,
 * `max` on a number — so the browser keeps somebody out of a state their own
 * save would refuse. A group of checkboxes is the one that has to be done by
 * hand, and a list of entries already is (its *add* button goes dead at `max`),
 * so this is that rule for the other item that counts.
 *
 * The floor is deliberately not guarded. Too few is allowed while somebody is
 * still filling the form in, so there would be nothing to stop — and stopping
 * somebody from unticking their own answer is not a form, it is a trap.
 *
 * The server still decides. This only saves a person from being told no for a
 * reason the page could see coming.
 */
export default class extends Controller {
    connect() {
        // What the server drew may already be at the ceiling: a document put
        // back, or one somebody saved earlier.
        this.guard();
    }

    guard() {
        const max = Number(this.element.dataset.max ?? 0);

        if (!max) return;

        const ticks = [...this.element.querySelectorAll('input[type="checkbox"]')];
        const picked = ticks.filter((tick) => tick.checked).length;

        for (const tick of ticks) tick.disabled = picked >= max && !tick.checked;
    }
}
