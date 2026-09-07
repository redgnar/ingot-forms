import { Controller } from '@hotwired/stimulus';

/**
 * One form on several pages.
 *
 * Every page is in the markup and all but one are hidden, because a step is a way
 * of *looking*: whatever page somebody is on, a save sends the whole form. That
 * is the invariant this controller must not break, and it is why nothing here
 * touches a value.
 *
 * Nothing is gated either — next always moves. A page that stopped somebody for
 * being under a minimum would be enforcing an obligation the server itself only
 * asks about at confirmation, and the kits already promise the opposite: a
 * ceiling is held before it is met, a floor never is.
 *
 * Two of these on one page are fine: each steps its own pages, and everything
 * below is scoped to `this.element`.
 */
export default class extends Controller {
    static targets = ['step', 'mark', 'status', 'back', 'next'];
    static values = { status: String };

    connect() {
        // After every other controller is up: which pages are worth showing
        // depends on which questions are asked, and that is worked out by the
        // form controller once it has connected.
        setTimeout(() => this.#show(this.#steps()[0]));
    }

    /** The form controller asked its conditions again, so the pages may have changed. */
    refresh() {
        this.#show(this.#current());
    }

    back(event) {
        this.#move(event, -1);
    }

    next(event) {
        this.#move(event, 1);
    }

    /** A mark is a way to go straight to a page: nothing is gated, so nothing is in the way. */
    jump(event) {
        this.#show(this.stepTargets[this.markTargets.indexOf(event.currentTarget)], event.isTrusted);
    }

    /**
     * A message nobody can see is not a message, and a page not being drawn hides
     * one as surely as a folded entry does. The form controller dispatches this
     * at the control a refusal is about; the caret follows afterwards, which is
     * why nothing is focused here.
     */
    reveal(event) {
        const step = event.target.closest('[data-step]');

        if (step !== null && this.stepTargets.includes(step)) this.#show(step);
    }

    #move(event, by) {
        event.preventDefault();

        const shown = this.#steps();
        const going = shown[shown.indexOf(this.#current()) + by];

        // Somebody who pressed "next" is about to answer what is on the page
        // they asked for. A page opened by a refusal moves nobody: the caret is
        // going to the control the refusal is about.
        if (going !== undefined) this.#show(going, event.isTrusted);
    }

    /**
     * The pages worth showing. A condition can empty a whole one, and a page with
     * nothing left to answer is stepped over rather than landed on — while a page
     * holding anything else visible ("review and send" holds a trigger and no
     * controls) is never skipped.
     */
    #steps() {
        return this.stepTargets.filter((step) => {
            const asked = [...step.querySelectorAll('[data-name][data-type], [data-collection]')]
                .filter((control) => control.closest('[data-unasked]') === null);

            if (asked.length > 0) return true;

            return [...step.querySelectorAll('button, a, h2, p, table, [data-history]')]
                .some((thing) => thing.closest('[data-unasked]') === null);
        });
    }

    #current() {
        return this.stepTargets.find((step) => !step.hidden) ?? null;
    }

    #show(step, answering = false) {
        const shown = this.#steps();

        if (shown.length === 0) return;

        // The page asked for, unless it is one nobody is being asked — then the
        // first that is.
        const going = step !== null && shown.includes(step) ? step : shown[0];

        for (const one of this.stepTargets) one.hidden = one !== going;

        this.markTargets.forEach((mark, index) => {
            const marked = this.stepTargets[index];
            // A page nobody is being asked is not a place to go to.
            mark.hidden = marked === undefined || !shown.includes(marked);

            if (marked === going) {
                mark.setAttribute('aria-current', 'step');
                // A track long enough to scroll is no use if the place somebody
                // is on is off the end of it. `nearest` on both axes, so this
                // never scrolls the page itself.
                mark.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            } else {
                mark.removeAttribute('aria-current');
            }
        });

        const at = shown.indexOf(going);

        if (this.hasStatusTarget) {
            this.statusTarget.textContent = this.statusValue
                .replace('{n}', String(at + 1))
                .replace('{m}', String(shown.length));
        }

        if (this.hasBackTarget) this.backTarget.disabled = at === 0;
        if (this.hasNextTarget) this.nextTarget.disabled = at === shown.length - 1;

        if (answering) {
            going.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea, button')?.focus();
        }
    }
}
