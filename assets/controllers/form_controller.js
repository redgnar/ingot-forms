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
    static values = { id: String };
    static targets = ['saved', 'problem', 'error', 'control'];

    save(event) {
        event.preventDefault();

        this.#send('/data', 'PUT', this.#collect()).then((ok) => {
            if (ok) this.savedTarget.classList.remove('d-none');
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

    // The notice says that what is on the page is what the form holds, so the
    // first thing somebody changes afterwards takes it away.
    touched() {
        this.savedTarget.classList.add('d-none');
    }

    #collect() {
        const values = {};

        for (const control of this.controlTargets) {
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

            values[name] = control.dataset.type === 'number' ? Number(raw) : raw;
        }

        return values;
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
        this.savedTarget.classList.add('d-none');
        this.problemTarget.classList.add('d-none');
        this.problemTarget.textContent = '';

        for (const slot of this.errorTargets) {
            slot.classList.add('d-none');
            slot.textContent = '';
        }

        for (const control of this.controlTargets) {
            control.classList.remove('is-invalid');
        }
    }

    // A refusal points at the item it is about (`/email`), so each message lands
    // beside the control it belongs to; anything else is shown once, on top.
    #showErrors(body) {
        const rest = [];

        for (const error of body.errors ?? []) {
            const name = (error.pointer ?? '').replace(/^\//, '');
            const slot = this.errorTargets.find((candidate) => candidate.dataset.error === name);

            if (slot === undefined) {
                rest.push(error.message);
                continue;
            }

            slot.textContent = error.message;
            slot.classList.remove('d-none');

            for (const control of this.controlTargets) {
                if (control.dataset.name === name) control.classList.add('is-invalid');
            }
        }

        if (rest.length === 0 && (body.errors ?? []).length > 0) return;

        this.problemTarget.textContent = rest.join(' ') || body.detail || body.title || 'The request was refused.';
        this.problemTarget.classList.remove('d-none');
    }
}
