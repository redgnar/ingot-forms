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

    /**
     * Bytes made somewhere else on the page — the `signature` pad draws a PNG and
     * hands it over here. Whatever produced them, they go up the same way and are
     * judged by the same answer, which is why a signature needed no upload path
     * of its own.
     */
    handed(event) {
        const file = event.detail;

        if (file instanceof File) this.#send(file);
    }

    /**
     * Starting again, asked for by something beside the picker. The same as
     * pressing the picker's own remove.
     */
    cleared(event) {
        this.forget(event);
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
        this.#discard();
        this.#hold(null);
    }

    #discard() {
        const held = this.#reference();

        if (held !== null) fetch(`${this.uploadValue}/${held.id}`, { method: 'DELETE' }).catch(() => {});
    }

    // A description handed over from somewhere else on the page — what somebody
    // had attached before they went to look at an earlier version.
    place(event) {
        this.#hold(event.detail ?? null);
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

        // Where the save can find it. Pressing save while bytes are still going
        // up used to lose them silently — the hidden control was still empty, so
        // the member was simply absent from the document — and with a pad that
        // uploads on its own the moment a stroke ends, that stopped being a rare
        // race ({@see form_controller.js}).
        this.element.filePending = new Promise((settled) => {
            request.addEventListener('loadend', () => {
                this.element.filePending = null;
                settled();
            });
        });

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

        // The one it replaces goes now, and not a moment earlier: redrawing a
        // signature is three or four uploads before somebody likes it, and every
        // one of them that nothing names is a file in the store nobody will ever
        // fetch. Doing it before the new one landed would leave the page holding
        // a file that had been deleted if the upload failed. The endpoint decides
        // which of them may go — a file a stored save names answers 409 and stays
        // — so nothing here has to know the difference.
        this.#discard();
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

        // Said out loud, because for some files the *name* is the least
        // interesting thing about them: a signature is an image, and the widget
        // drawn on top of this one shows it. Announced with the address too —
        // this is where a file's address is built, and there is no reason for a
        // second place to know how.
        this.dispatch('held', {
            detail: reference === null ? null : { reference, href: `${this.uploadValue}/${reference.id}` },
        });

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
        this.barTarget.setAttribute('aria-valuenow', sending ? '0' : '100');

        if (sending) this.#say('');
    }

    #drawProgress(event) {
        if (!this.hasBarTarget || !event.lengthComputable) return;

        const done = Math.round((event.loaded / event.total) * 100);

        // The bar is a picture of this number; the number is the part that can be
        // read out loud.
        this.barTarget.style.width = `${done}%`;
        this.barTarget.setAttribute('aria-valuenow', String(done));
    }

    // The refusal goes where every refusal about this item goes, so a person
    // reads it under the control it is about.
    #say(text) {
        const slot = this.element.closest('[data-item]')?.querySelector('[data-error]');

        if (slot === null || slot === undefined) return;

        slot.textContent = text;
        slot.classList.toggle('d-none', text === '');

        // The picker is the control the label points at, so it is the one that
        // has to say it was refused.
        if (!this.hasPickerTarget) return;

        if (text === '') {
            this.pickerTarget.removeAttribute('aria-invalid');
        } else {
            this.pickerTarget.setAttribute('aria-invalid', 'true');
        }
    }
}
