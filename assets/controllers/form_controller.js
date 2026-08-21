import { Controller } from '@hotwired/stimulus';

/**
 * The page's side of the form: collect what the controls hold, send it to the
 * API, and show what comes back where it belongs.
 *
 * The markup says which control holds which item (`data-name`) and what kind of
 * value it is (`data-type`) — the renderer knows both from the definition, and a
 * control only ever holds text. That convention is shared with the `core-html`
 * kit; the machinery around it is not, because the two kits deliberately differ
 * in how much of it they carry.
 */
export default class extends Controller {
    // The sentence for a refusal that names no item comes from the page, which
    // got it from this application's own catalogue: a controller has no business
    // holding English.
    static values = { id: String, page: String, refused: String };
    static targets = ['saved', 'problem', 'problemText', 'error', 'control'];

    save(event) {
        event.preventDefault();

        this.#send('/data', 'PUT', this.#collect()).then((ok) => {
            if (!ok) return;

            if (this.hasSavedTarget) this.savedTarget.classList.remove('d-none');
            // A save makes a new moment, and a panel that does not show it is
            // lying about what this form remembers.
            this.dispatch('saved', { prefix: 'form' });
        });
    }

    // Confirming judges what the form holds, so what is on the page has to be in
    // it first. A form that locks is drawn again, read-only, by the server.
    async confirm(event) {
        event.preventDefault();

        if (await this.#send('/data', 'PUT', this.#collect())) {
            if (await this.#send('/confirm', 'POST')) window.location.reload();
        }
    }

    // Back to what the form holds. Drawn again by the server rather than undone
    // control by control: the page that shows what is stored is the page the
    // server sends, and reproducing it here would be a second answer to the same
    // question.
    reset(event) {
        event.preventDefault();
        window.location.assign(this.pageValue);
    }

    // Putting a version back is an ordinary draft save of a document this page
    // happens to have read — the same gates, the same refusals. Afterwards the
    // form is drawn again, because every control on it has just changed.
    async restoreVersion(event) {
        event.preventDefault();
        const seq = event.currentTarget.dataset.historyRestore;
        const response = await fetch(`/api/forms/${this.idValue}/history/${seq}`);

        if (response.ok && (await this.#send('/data', 'PUT', await response.json()))) {
            window.location.assign(this.pageValue);
        }
    }

    // The notice says that what is on the page is what the form holds, so the
    // first thing somebody changes afterwards takes it away.
    touched() {
        if (this.hasSavedTarget) this.savedTarget.classList.add('d-none');
    }

    // Structure carries identity: what a control answers is read from where it
    // sits, so a list's entries are collected in the order they appear on the
    // page and nothing has to be renumbered when one is added or removed.
    #collect(scope = this.element) {
        const values = {};

        for (const control of this.#ownControls(scope)) {
            const name = control.dataset.name;

            if (control.dataset.choice !== undefined) {
                const picked = control.querySelector('input:checked');
                if (picked) values[name] = picked.value;
                continue;
            }

            if (control.type === 'checkbox') {
                values[name] = control.checked;
                continue;
            }

            const raw = control.value;
            if (raw === '') continue;

            // A file's value is a whole document — the description the upload
            // answered with — carried in a hidden control as the JSON it is.
            const type = control.dataset.type;
            values[name] = type === 'json' ? JSON.parse(raw) : type === 'number' ? Number(raw) : raw;
        }

        for (const list of this.#ownLists(scope)) {
            values[list.dataset.collection] = this.#entriesOf(list).map((entry) => this.#collect(entry));
        }

        return values;
    }

    // Everything belonging to this scope rather than to a list inside it. A
    // blank entry waiting to be cloned belongs to nobody until it is added.
    #ownControls(scope) {
        return this.controlTargets.filter((control) => scope.contains(control) && this.#listOwning(control, scope) === null);
    }

    #ownLists(scope) {
        return [...scope.querySelectorAll('[data-collection]')].filter(
            (list) => this.#listOwning(list.parentElement, scope) === null && list.closest('template') === null,
        );
    }

    #listOwning(element, scope) {
        const found = element?.closest('[data-collection]');

        return found !== null && found !== undefined && found !== scope && scope.contains(found) ? found : null;
    }

    #entriesOf(list) {
        return [...list.querySelectorAll('[data-entry]')].filter(
            (entry) => entry.closest('[data-collection]') === list && entry.closest('template') === null,
        );
    }

    async #send(path, method, body) {
        this.#clearMessages();

        const response = await fetch(`/api/forms/${this.idValue}${path}`, {
            method,
            headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        if (response.ok) return true;

        this.#showErrors(await response.json().catch(() => ({})));

        return false;
    }

    #clearMessages() {
        // A page drawn from an earlier save carries no notices — there is nothing
        // to save there, only a version to put back — so this controller has to
        // work without them.
        if (this.hasSavedTarget) this.savedTarget.classList.add('d-none');

        if (this.hasProblemTarget) {
            this.problemTarget.classList.add('d-none');
            this.problemTextTarget.textContent = '';
        }

        for (const slot of this.errorTargets) {
            slot.classList.add('d-none');
            slot.textContent = '';
        }

        for (const control of this.controlTargets) {
            control.classList.remove('is-invalid');
        }

        for (const row of this.element.querySelectorAll('tr.table-danger')) {
            row.classList.remove('table-danger');
        }
    }

    // A message nobody can see is not a message. An entry is answered in a form
    // that is folded away, so a refusal about it unfolds every form on the way to
    // it — and marks each row it is inside, so the table still says "look here"
    // once somebody folds it back up.
    #reveal(slot) {
        for (let form = slot.closest('details'); form !== null; form = form.parentElement?.closest('details') ?? null) {
            form.open = true;
        }

        for (let entry = slot.closest('[data-entry]'); entry !== null; entry = entry.parentElement?.closest('[data-entry]') ?? null) {
            entry.querySelector('tr')?.classList.add('table-danger');
        }
    }

    // A refusal points at the item it is about — `/email`, or `/lines/2/quantity`
    // for the third entry of a list — so the pointer is walked the same way the
    // values were collected, and the message lands beside the control it belongs
    // to. Anything else is shown once, on top.
    #slotFor(pointer) {
        const steps = pointer.split('/').filter((step) => step !== '');
        let scope = this.element;

        while (steps.length > 0) {
            const step = steps.shift();

            if (/^\d+$/.test(step)) {
                scope = this.#entriesOf(scope)[Number(step)];
                if (scope === undefined) return null;
                continue;
            }

            if (steps.length > 0) {
                scope = this.#ownLists(scope).find((list) => list.dataset.collection === step);
                if (scope === undefined) return null;
                continue;
            }

            return this.errorTargets.find(
                (slot) => scope.contains(slot) && this.#listOwning(slot, scope) === null && slot.dataset.error === step,
            ) ?? null;
        }

        return null;
    }

    #showErrors(body) {
        const rest = [];

        for (const error of body.errors ?? []) {
            const slot = this.#slotFor(error.pointer ?? '');

            if (slot === null) {
                rest.push(error.message);
                continue;
            }

            slot.textContent = error.message;
            slot.classList.remove('d-none');
            this.#reveal(slot);

            const entry = slot.closest('[data-entry]') ?? this.element;

            for (const control of this.controlTargets) {
                if (control.dataset.name === slot.dataset.error && (control.closest('[data-entry]') ?? this.element) === entry) {
                    control.classList.add('is-invalid');
                }
            }
        }

        if (rest.length === 0 && (body.errors ?? []).length > 0) return;

        if (!this.hasProblemTarget) return;

        this.problemTextTarget.textContent = rest.join(' ') || body.detail || body.title || this.refusedValue;
        this.problemTarget.classList.remove('d-none');
    }
}
