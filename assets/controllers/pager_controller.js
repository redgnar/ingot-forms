import { Controller } from '@hotwired/stimulus';

/**
 * One form in parts, shown one at a time — the mechanism under both looks a
 * document may ask for: a `wizard` of `step`s and a `tabs` of `tab`s.
 *
 * Every page is in the markup and all but one are hidden, because paging is a way
 * of *looking*: whatever page somebody is on, a save sends the whole form. That
 * is the invariant this controller must not break, and it is why nothing here
 * touches a value.
 *
 * What differs between the two looks is what a reader is told and how they move,
 * and it is exactly three things below: which attribute marks the current page
 * (`aria-current="step"` in a sequence, `aria-selected` among peers), whether the
 * strip is one tab stop with arrows between the marks, and whether there is a
 * *next* to disable at all. The pager says which look it is
 * (`data-pager="steps"`/`"tabs"`), because a controller sniffing its own markup
 * for a class name is a controller that breaks when a skin renames one.
 *
 * Nothing is gated either — next always moves. A page that stopped somebody for
 * being under a minimum would be enforcing an obligation the server itself only
 * asks about at confirmation, and the kits already promise the opposite: a
 * ceiling is held before it is met, a floor never is.
 *
 * Two of these on one page are fine — a document may place a wizard and a strip
 * of tabs, or two of either: each shows its own pages, and everything below is
 * scoped to `this.element`.
 */
export default class extends Controller {
    static targets = ['page', 'mark', 'status', 'back', 'next'];
    static values = { status: String };

    /** Peers rather than a sequence: the roles, the keyboard and what is said all follow from this. */
    get #amongPeers() {
        return this.element.dataset.pager === 'tabs';
    }

    connect() {
        // After every other controller is up: which pages are worth showing
        // depends on which questions are asked, and that is worked out by the
        // form controller once it has connected.
        setTimeout(() => this.#show(this.#pages()[0]));
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
        this.#show(this.pageTargets[this.markTargets.indexOf(event.currentTarget)], event.isTrusted);
    }

    /**
     * Arrows move between tabs, which is the whole reason the strip is one tab
     * stop: a reader arrives at the sections once, not once per section. Only
     * among peers — in a sequence the marks are ordinary buttons and the arrow
     * keys belong to whatever the reader is answering.
     */
    steer(event) {
        if (!this.#amongPeers) return;

        const shown = this.#pages();
        const at = shown.indexOf(this.#current());
        const going = {
            ArrowRight: shown[at + 1],
            ArrowLeft: shown[at - 1],
            Home: shown[0],
            End: shown[shown.length - 1],
        }[event.key];

        if (going === undefined) return;

        event.preventDefault();
        this.#show(going);
        // The mark, not the panel: somebody steering with the keyboard is still
        // in the strip, and the next arrow has to move from where they are.
        this.#markOf(going)?.focus();
    }

    /**
     * A message nobody can see is not a message, and a page not being drawn hides
     * one as surely as a folded entry does. The form controller dispatches this
     * at the control a refusal is about; the caret follows afterwards, which is
     * why nothing is focused here.
     */
    reveal(event) {
        const page = event.target.closest('[data-page]');

        if (page !== null && this.pageTargets.includes(page)) this.#show(page);
    }

    #move(event, by) {
        event.preventDefault();

        const shown = this.#pages();
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
    #pages() {
        return this.pageTargets.filter((page) => {
            const asked = [...page.querySelectorAll('[data-name][data-type], [data-collection]')]
                .filter((control) => control.closest('[data-unasked]') === null);

            if (asked.length > 0) return true;

            return [...page.querySelectorAll('button, a, h2, p, table, [data-history]')]
                .some((thing) => thing.closest('[data-unasked]') === null);
        });
    }

    #current() {
        return this.pageTargets.find((page) => !page.hidden) ?? null;
    }

    #markOf(page) {
        return this.markTargets[this.pageTargets.indexOf(page)] ?? null;
    }

    #show(page, answering = false) {
        const shown = this.#pages();

        if (shown.length === 0) return;

        // The page asked for, unless it is one nobody is being asked — then the
        // first that is.
        const going = page !== null && shown.includes(page) ? page : shown[0];

        for (const one of this.pageTargets) one.hidden = one !== going;

        this.markTargets.forEach((mark, index) => {
            const marked = this.pageTargets[index];
            // A page nobody is being asked is not a place to go to.
            mark.hidden = marked === undefined || !shown.includes(marked);

            if (this.#amongPeers) {
                mark.setAttribute('aria-selected', String(marked === going));
                // One tab stop for the strip: the tab somebody is on is the one
                // the caret can reach, and the arrows do the rest.
                mark.tabIndex = marked === going ? 0 : -1;
            }

            if (marked === going) {
                if (!this.#amongPeers) mark.setAttribute('aria-current', 'step');
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
