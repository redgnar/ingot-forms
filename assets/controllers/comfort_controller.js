import { Controller } from '@hotwired/stimulus';

/**
 * The three things a reader can ask of this page: darker colours, more contrast,
 * bigger text.
 *
 * None of it reaches the server. This service has no idea who anybody is, so
 * there is nowhere to keep a person's preference except the browser they are
 * reading in — which is where it belongs anyway, being a fact about a screen and
 * a pair of eyes rather than about a form.
 *
 * The page has already applied what it found in storage before this controller
 * existed: an inline script in the head, because a preference applied after the
 * first paint is one the reader watches being applied. What is left here is to
 * show which switches are on, and to write a change down.
 *
 * Each switch is a plain on/off. There used to be a third state for the colours
 * — "follow the system" — and on a machine that says "light" it was a second
 * button that did what the first one did. What the machine says is still where
 * the page starts; it is just no longer something to press.
 */
export default class extends Controller {
    static targets = ['dark', 'contrast', 'text'];

    static values = { stash: { type: String, default: 'ingot-forms' } };

    // switch → the attribute it sets on <html>, what "on" means, and what to
    // remember it as. Bootstrap reads `data-bs-theme` itself; the other two are
    // ours, and read by `comfort.css`.
    static SWITCHES = {
        dark: { attribute: 'bsTheme', on: 'dark', off: 'light', stash: 'theme' },
        contrast: { attribute: 'contrast', on: 'high', off: 'off', stash: 'contrast' },
        text: { attribute: 'text', on: 'large', off: 'off', stash: 'text' },
    };

    connect() {
        for (const [name, how] of Object.entries(this.constructor.SWITCHES)) {
            if (this[`has${name[0].toUpperCase()}${name.slice(1)}Target`]) {
                this.#reflect(this[`${name}Target`], document.documentElement.dataset[how.attribute] === how.on);
            }
        }

        // A panel laid over the page is closed the way anything laid over a page
        // is closed: by looking somewhere else, or by pressing Escape. Making
        // somebody find the button again to put it away is making them aim twice.
        this.dismiss = (event) => {
            if (this.element.open && !this.element.contains(event.target)) this.element.open = false;
        };
        this.escape = (event) => {
            if (event.key !== 'Escape' || !this.element.open) return;

            this.element.open = false;
            this.element.querySelector('summary')?.focus();
        };

        document.addEventListener('click', this.dismiss);
        document.addEventListener('keydown', this.escape);
    }

    disconnect() {
        document.removeEventListener('click', this.dismiss);
        document.removeEventListener('keydown', this.escape);
    }

    dark(event) {
        this.#toggle(event.currentTarget, 'dark');
    }

    contrast(event) {
        this.#toggle(event.currentTarget, 'contrast');
    }

    text(event) {
        this.#toggle(event.currentTarget, 'text');
    }

    #toggle(button, name) {
        const how = this.constructor.SWITCHES[name];
        const root = document.documentElement;
        const wanted = root.dataset[how.attribute] !== how.on;

        if (wanted) {
            root.dataset[how.attribute] = how.on;
        } else {
            delete root.dataset[how.attribute];
        }

        this.#reflect(button, wanted);
        // Written down either way: a reader whose machine asks for dark, or
        // whose document prefers it, and who turns it off here means that, and
        // must not be given it back by the next page.
        this.#remember(how.stash, wanted ? how.on : how.off);
    }

    #reflect(button, on) {
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.classList.toggle('active', on);
    }

    #remember(name, value) {
        try {
            localStorage.setItem(`${this.stashValue}:${name}`, value);
        } catch {
            // No storage to keep it in (a private window, storage turned off).
            // The choice holds for this page and is forgotten on the next one,
            // which is worse than remembering it and better than refusing it.
        }
    }
}
