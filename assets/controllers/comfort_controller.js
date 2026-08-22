import { Controller } from '@hotwired/stimulus';

/**
 * The three things a reader can ask of this page: which colours, how much
 * contrast, how big the text.
 *
 * None of it reaches the server. This service has no idea who anybody is, so
 * there is nowhere to keep a person's preference except the browser they are
 * reading in — which is where it belongs anyway, being a fact about a screen and
 * a pair of eyes rather than about a form.
 *
 * The page has already applied what it found in storage before this controller
 * existed: an inline script in the head, because a preference applied after the
 * first paint is a preference the reader watches being applied. What is left
 * here is to show which one they are on, to write a change down, and to keep
 * following the machine while they are on "system" — which means what the
 * machine says now, not what it said when the page was opened.
 */
export default class extends Controller {
    static targets = ['theme', 'contrast', 'text'];

    static values = { stash: { type: String, default: 'ingot-forms' } };

    connect() {
        const root = document.documentElement;

        for (const choice of this.themeTargets) {
            choice.checked = choice.value === (root.dataset.theme ?? 'auto');
        }

        this.#reflect(this.contrastTarget, root.dataset.contrast === 'high');
        this.#reflect(this.textTarget, root.dataset.text === 'large');

        this.system = window.matchMedia('(prefers-color-scheme: dark)');
        this.follow = () => this.#paint();
        this.system.addEventListener('change', this.follow);
    }

    disconnect() {
        this.system.removeEventListener('change', this.follow);
    }

    theme(event) {
        document.documentElement.dataset.theme = event.target.value;
        this.#remember('theme', event.target.value);
        this.#paint();
    }

    contrast(event) {
        this.#toggle(event.currentTarget, 'contrast', 'high');
    }

    text(event) {
        this.#toggle(event.currentTarget, 'text', 'large');
    }

    // What the reader chose is one thing; which colours that comes to is
    // another, and only the choice is worth remembering.
    #paint() {
        const root = document.documentElement;
        const chosen = root.dataset.theme ?? 'auto';

        if (chosen === 'dark' || (chosen === 'auto' && this.system.matches)) {
            root.dataset.bsTheme = 'dark';
        } else {
            delete root.dataset.bsTheme;
        }
    }

    #toggle(button, name, on) {
        const root = document.documentElement;
        const wanted = root.dataset[name] !== on;

        if (wanted) {
            root.dataset[name] = on;
        } else {
            delete root.dataset[name];
        }

        this.#reflect(button, wanted);
        // Written down either way: a reader whose machine asks for contrast and
        // who turned it off here means it, and must not be given it back by the
        // next page.
        this.#remember(name, wanted ? on : 'off');
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
