import { Controller } from '@hotwired/stimulus';

/**
 * Earlier versions of this form: the saves it has had, one of them read back, and
 * a way to put an answer — or the whole version — back.
 *
 * Nothing is fetched until somebody opens the panel: a page nobody looks into
 * should not pay for it. Every row drawn here is a clone of a template the server
 * rendered, so this controller moves markup rather than writing it.
 *
 * Putting one answer back writes into the control and sends nothing: somebody puts
 * it back, looks at the form, and saves when they mean to. Putting a whole version
 * back is an ordinary draft save of a document this page happens to have read —
 * the same gates, the same refusals — after which the server draws the page again,
 * because every control on it has just changed.
 */
export default class extends Controller {
    static values = { id: String };
    static targets = ['list', 'empty', 'failed', 'version', 'members', 'moment', 'member'];

    async opened() {
        if (!this.element.open || this.loaded) return;

        this.loaded = true;
        const revisions = await this.#read('');

        if (revisions === null) return;

        this.listTarget.textContent = '';
        this.emptyTarget.classList.toggle('d-none', revisions.revisions.length > 0);

        for (const revision of revisions.revisions) {
            const row = this.momentTarget.content.cloneNode(true);
            const when = row.querySelector('[data-history-when]');

            when.textContent = new Date(revision.savedAt).toLocaleString();
            when.dateTime = revision.savedAt;
            row.querySelector('[data-history-open]').dataset.historyOpen = revision.seq;
            row.querySelector('[data-history-confirmed]').classList.toggle('d-none', !revision.confirmed);
            this.listTarget.append(row);
        }
    }

    async show(event) {
        event.preventDefault();
        const seq = event.currentTarget.dataset.historyOpen;
        const version = await this.#read(`/${seq}`);

        if (version === null) return;

        this.showing = seq;
        this.membersTarget.textContent = '';
        this.versionTarget.classList.remove('d-none');

        for (const [name, value] of Object.entries(version)) {
            const row = this.memberTarget.content.cloneNode(true);
            row.querySelector('[data-history-name]').textContent = name;
            row.querySelector('[data-history-value]').textContent = this.#reads(value);

            const put = row.querySelector('[data-history-put]');

            // Only what one control holds. A list is many controls and a file is a
            // description with a chip beside it — those two go back with the whole
            // version, which is the button above.
            if (put !== null && this.#controlFor(name) !== null && (value === null || typeof value !== 'object')) {
                put.dataset.historyPut = name;
                put.classList.remove('d-none');
            }

            this.membersTarget.append(row);
        }
    }

    async put(event) {
        event.preventDefault();
        const name = event.currentTarget.dataset.historyPut;
        const version = await this.#read(`/${this.showing}`);
        const control = this.#controlFor(name);

        if (version === null || control === null) return;

        this.#place(control, version[name]);
    }

    async restore(event) {
        event.preventDefault();
        const version = await this.#read(`/${this.showing}`);

        if (version === null) return;

        const response = await fetch(`/api/forms/${this.idValue}/data`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(version),
        });

        if (response.ok) window.location.reload();
        else this.failedTarget.classList.remove('d-none');
    }

    async #read(path) {
        try {
            const response = await fetch(`/api/forms/${this.idValue}/history${path}`);

            if (response.ok) return await response.json();
        } catch {
            // Answered below, in the page's own words.
        }

        this.failedTarget.classList.remove('d-none');

        return null;
    }

    #reads(value) {
        if (value === null || typeof value !== 'object') return String(value);
        // A file reads as what it is called, like it does in a list's own row.
        if (typeof value.name === 'string' && typeof value.id === 'string') return value.name;

        return JSON.stringify(value);
    }

    // The control that holds this answer at the top of the form — never one inside
    // an entry, because a member of a list is answered once per entry.
    #controlFor(name) {
        return [...document.querySelectorAll(`[data-name="${name}"]`)].find(
            (control) => control.closest('[data-entry]') === null && control.closest('template') === null,
        ) ?? null;
    }

    // The collector, backwards: what a control holds is written the way it is read.
    #place(control, value) {
        if (control.dataset.choice !== undefined) {
            for (const option of control.querySelectorAll('input')) option.checked = option.value === String(value);
        } else if (control.type === 'checkbox') {
            control.checked = Boolean(value);
        } else {
            control.value = String(value);
        }

        control.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
