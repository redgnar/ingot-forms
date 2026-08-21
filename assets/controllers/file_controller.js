import { Controller } from '@hotwired/stimulus';

/**
 * One file control: pick or drop bytes, send them, and hold on to what the
 * server says about what it stored.
 *
 * A file is not typed into a control. What the values document holds is the
 * description the upload answered with, so the hidden control beside the picker
 * is the value and the picker itself is only how somebody chooses — the form
 * controller collects one and never the other.
 *
 * The upload goes through `XMLHttpRequest` rather than `fetch` for one reason:
 * it reports how far it has got, which is the whole difference between this kit's
 * `dropzone` and a picker. Every sentence it shows comes from the page, which got
 * it from this application's own catalogue; a controller has no business holding
 * English.
 */
export default class extends Controller {
    static targets = ['held', 'picker', 'progress', 'bar', 'line', 'download'];
    static values = {
        upload: String,
        accept: String,
        maxSize: Number,
        uploading: String,
        tooLarge: String,
        rejected: String,
        failed: String,
    };

    picked() {
        const file = this.pickerTarget.files?.[0];

        if (file) this.#send(file);
    }

    over(event) {
        event.preventDefault();
        this.element.classList.add('border-primary');
    }

    leave() {
        this.element.classList.remove('border-primary');
    }

    dropped(event) {
        event.preventDefault();
        this.leave();
        const file = event.dataTransfer?.files?.[0];

        if (file) this.#send(file);
    }

    // Taking back what nothing names yet. A refusal is not this page's business:
    // 409 means a stored document still names the file, and then the next save is
    // what drops it — and the save is what throws it away.
    forget(event) {
        event.preventDefault();
        const held = this.#reference();

        this.#hold(null);

        if (held !== null) fetch(`${this.uploadValue}/${held.id}`, { method: 'DELETE' }).catch(() => {});
    }

    #send(file) {
        // Bigger than the form allows: the answer is known here, so nothing is
        // sent.
        if (file.size > this.maxSizeValue) {
            this.#say(this.tooLargeValue);
            this.#reset();

            return;
        }

        const body = new FormData();
        body.append('file', file);

        const request = new XMLHttpRequest();
        request.open('POST', this.uploadValue);
        request.upload.addEventListener('progress', (event) => this.#drawProgress(event));
        request.addEventListener('load', () => this.#landed(request));
        request.addEventListener('error', () => {
            this.#say(this.failedValue);
            this.#reset();
        });

        this.#say('');
        this.#showProgress(true);
        request.send(body);
    }

    #landed(request) {
        this.#showProgress(false);
        this.#reset();

        if (request.status !== 201) {
            this.#say(this.#detail(request) || this.failedValue);

            return;
        }

        const reference = JSON.parse(request.responseText);
        // What kind of bytes those are is the server's word, not the browser's —
        // so the item's own rule is checked against what came back. Nothing names
        // the file yet, so a refusal can take it away at once.
        const accepted = this.acceptValue.split(',').filter((type) => type !== '');

        if (accepted.length > 0 && !accepted.includes(reference.type)) {
            fetch(`${this.uploadValue}/${reference.id}`, { method: 'DELETE' }).catch(() => {});
            this.#say(this.rejectedValue);

            return;
        }

        this.#hold(reference);
    }

    #detail(request) {
        try {
            return JSON.parse(request.responseText).detail ?? '';
        } catch {
            return '';
        }
    }

    // Filling in markup the server rendered, never writing markup here: the line
    // that says which file is held is on the page already, waiting.
    #hold(reference) {
        this.heldTarget.value = reference === null ? '' : JSON.stringify(reference);

        if (reference !== null) {
            this.downloadTarget.textContent = reference.name;
            this.downloadTarget.href = `${this.uploadValue}/${reference.id}`;
        }

        this.lineTarget.classList.toggle('d-none', reference === null);
        this.#say('');
        // The row above an entry's form shows what the form holds, and this just
        // changed it.
        this.heldTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    #reference() {
        return this.heldTarget.value === '' ? null : JSON.parse(this.heldTarget.value);
    }

    #reset() {
        if (this.hasPickerTarget) this.pickerTarget.value = '';
    }

    #showProgress(sending) {
        if (!this.hasProgressTarget) return;

        this.progressTarget.classList.toggle('d-none', !sending);
        this.barTarget.style.width = sending ? '0' : '100%';

        if (sending) this.#say('');
    }

    #drawProgress(event) {
        if (!this.hasBarTarget || !event.lengthComputable) return;

        this.barTarget.style.width = `${Math.round((event.loaded / event.total) * 100)}%`;
    }

    // The refusal goes where every refusal about this item goes, so a person
    // reads it under the control it is about.
    #say(text) {
        const slot = this.element.closest('[data-item]')?.querySelector('[data-error]');

        if (slot === null || slot === undefined) return;

        slot.textContent = text;
        slot.classList.toggle('d-none', text === '');
    }
}
