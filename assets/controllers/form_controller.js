import { Controller } from '@hotwired/stimulus';
import { withOffset } from '../moments.js';

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
    // `api` is where this form is written to — the four addresses the server
    // generated, because a page that builds its own would stop being right the
    // moment this service is mounted anywhere but the root of a host. `id` stays
    // for what is nobody's address: the key this tab keeps unsaved answers under.
    static values = { id: String, api: Object, page: String, version: Number, refused: String, refusals: Object };
    static targets = ['saved', 'unsaved', 'problem', 'problemText', 'error', 'control'];

    // Looking at an earlier version means leaving this page, and leaving a page
    // throws away what nobody saved. So what is on it goes with you: kept for the
    // length of the detour, in this tab only, and taken back the moment you
    // return. Anything that settles the question — a save, a restore, starting
    // again — drops it.
    connect() {
        // On a version page, nothing is touched: what somebody typed is waiting
        // for them to come back, and this page is the middle of that trip rather
        // than the end.
        if (this.hasVersionValue && this.versionValue > 0) {
            return;
        }

        // After every controller on the page is up: filling a list presses the
        // button that adds one, and a button whose controller has not connected
        // yet does nothing at all.
        setTimeout(() => {
            const kept = this.#takeUnsaved();

            if (kept !== null) {
                this.#fill(this.element, kept);
                // A page that shows something other than what the server holds has
                // to admit it.
                if (this.hasUnsavedTarget) this.unsavedTarget.classList.remove('d-none');
            }

            // Whatever the page holds now — drawn by the server, or drawn and then
            // filled back in — is the baseline the next detour is measured against.
            this.asDrawn = JSON.stringify(this.#collect());
        });
    }

    // On the way to an earlier version, and only there: the one navigation this
    // page knows about in advance. Delegated from the whole page, because the link
    // that leads there is drawn by a panel this controller does not own.
    // The two links on this page that lead away from it and back: an earlier
    // version, and the same page in another language. Both are detours, and what
    // somebody typed goes with them.
    leaving(event) {
        if (event.target.closest('[data-history-view], [data-language]') !== null) this.#keepUnsaved();
    }

    async save(event) {
        event.preventDefault();
        await this.#settled();

        if (!await this.#send(this.apiValue.data, 'PUT', this.#collect())) return;

        this.#forgetUnsaved();
        this.asDrawn = JSON.stringify(this.#collect());
        if (this.hasSavedTarget) this.savedTarget.classList.remove('d-none');
        // A save makes a new moment, and a panel that does not show it is lying
        // about what this form remembers.
        this.dispatch('saved', { prefix: 'form' });
    }

    // Confirming judges what the form holds, so what is on the page has to be in
    // it first. A form that locks is drawn again, read-only, by the server.
    async confirm(event) {
        event.preventDefault();
        await this.#settled();

        if (await this.#send(this.apiValue.data, 'PUT', this.#collect())) {
            if (await this.#send(this.apiValue.confirm, 'POST')) window.location.reload();
        }
    }

    // Back to what the form holds. Drawn again by the server rather than undone
    // control by control: the page that shows what is stored is the page the
    // server sends, and reproducing it here would be a second answer to the same
    // question.
    reset(event) {
        event.preventDefault();
        this.#forgetUnsaved();
        window.location.assign(this.pageValue);
    }

    // Putting a version back is an ordinary draft save of a document this page
    // happens to have read — the same gates, the same refusals. Afterwards the
    // form is drawn again, because every control on it has just changed.
    async restoreVersion(event) {
        event.preventDefault();
        this.#forgetUnsaved();
        const seq = event.currentTarget.dataset.historyRestore;
        const response = await fetch(`${this.apiValue.history}/${seq}`);

        if (response.ok && (await this.#send(this.apiValue.data, 'PUT', await response.json()))) {
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

            // Several answers out of one control: a group of ticks, or a
            // `select multiple` (which is what an autocomplete asked for
            // several is underneath). Read from the markup rather than off
            // `value`, which for either of them says one thing or nothing.
            // Nothing picked leaves the member out, exactly as an unanswered
            // choice does — and since a save replaces the whole document,
            // unticking everything and saving is how somebody takes their
            // answer back.
            if (control.dataset.type === 'strings') {
                const picked = control.tagName === 'SELECT'
                    ? [...control.selectedOptions].map((option) => option.value)
                    : [...control.querySelectorAll('input:checked')].map((tick) => tick.value);

                if (picked.length > 0) values[name] = picked;
                continue;
            }

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

            // A wall clock on the way back to being a moment: the control has no
            // offset in it, and the API takes nothing else.
            if (control.type === 'datetime-local') {
                values[name] = withOffset(raw);
                continue;
            }

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

    // Bytes still going up are bytes the document does not name yet, and the
    // hidden control that carries a file's description is empty until its upload
    // lands. A save a moment too early would send a document with the member
    // simply missing — which is what it looked like before this waited, and what
    // a signature pad uploading on its own would have made ordinary.
    async #settled() {
        await Promise.all(
            [...this.element.querySelectorAll('[data-controller~="file"]')]
                .map((widget) => widget.filePending)
                .filter((pending) => pending !== null && pending !== undefined),
        );
    }

    async #send(url, method, body) {
        this.#clearMessages();

        const response = await fetch(url, {
            method,
            headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        // A save answers 204 and nothing else does, so nothing else is a save.
        // Something in front of this service can answer instead of it — a proxy
        // refusing, or an expired session redirecting to a login page, which
        // `fetch` follows and hands back as 200 with HTML — and `ok` would read
        // every one of those as answers that were stored.
        if (response.status === 204) return true;

        this.#showErrors(await response.json().catch(() => ({})));

        return false;
    }

    // The collector, backwards: putting a document onto the page the same way it is
    // read off it. It walks the same structure, so a list inside a list comes back
    // as one too.
    #fill(scope, values) {
        for (const control of this.#ownControls(scope)) {
            this.#place(control, values[control.dataset.name] ?? null);
        }

        for (const list of this.#ownLists(scope)) {
            const entries = Array.isArray(values[list.dataset.collection]) ? values[list.dataset.collection] : [];
            const rows = () => this.#entriesOf(list);

            while (rows().length > entries.length) {
                const before = rows().length;
                rows().at(-1).querySelector('[data-entries-target="remove"]')?.click();

                if (rows().length === before) break;
            }

            // Pressed the way a person presses it: one path for one more entry —
            // and bounded, because a button whose controller is not listening does
            // nothing, and a loop waiting for it would take the browser down.
            while (rows().length < entries.length) {
                const before = rows().length;
                list.querySelector('[data-entries-target="add"]')?.click();

                if (rows().length === before) break;
            }

            rows().forEach((entry, index) => this.#fill(entry, entries[index]));
        }
    }

    #place(control, value) {
        if (control.dataset.type === 'strings') {
            const picked = Array.isArray(value) ? value.map(String) : [];

            if (control.tagName === 'SELECT') {
                for (const option of control.options) option.selected = picked.includes(option.value);
                // An autocomplete draws its own chips off the select it wraps,
                // and does not watch it: `sync()` is how it is told.
                control.tomselect?.sync();
            } else {
                for (const tick of control.querySelectorAll('input')) tick.checked = picked.includes(tick.value);
            }
        } else if (control.dataset.choice !== undefined) {
            for (const option of control.querySelectorAll('input')) {
                option.checked = value !== null && option.value === String(value);
            }
        } else if (control.type === 'checkbox') {
            control.checked = value === true;
        } else if (control.dataset.type === 'json') {
            // A file is a description plus the line that says which one is held,
            // and the controller that draws that line is the one that owns it.
            control.closest('[data-controller~="file"]')?.dispatchEvent(new CustomEvent('file:place', { detail: value }));
        } else {
            control.value = value === null ? '' : String(value);
        }

        control.dispatchEvent(new Event('input', { bubbles: true }));
    }

    #keepUnsaved() {
        const now = JSON.stringify(this.#collect());

        // Nothing was typed, so there is nothing to carry — and saying "these are
        // not saved" about the form's own values would be a lie.
        if (now === this.asDrawn) {
            this.#forgetUnsaved();

            return;
        }

        try {
            sessionStorage.setItem(this.#stash(), now);
        } catch {
            // No storage to keep it in (a private window, storage turned off): the
            // detour costs what it used to cost, and nothing else breaks.
        }
    }

    #takeUnsaved() {
        try {
            const kept = sessionStorage.getItem(this.#stash());
            sessionStorage.removeItem(this.#stash());

            return kept === null ? null : JSON.parse(kept);
        } catch {
            return null;
        }
    }

    #forgetUnsaved() {
        try {
            sessionStorage.removeItem(this.#stash());
        } catch {
            // Nothing was kept, so nothing is left behind.
        }
    }

    #stash() {
        return `ingot-forms:unsaved:${this.idValue}`;
    }

    #clearMessages() {
        // A page drawn from an earlier save carries no notices — there is nothing
        // to save there, only a version to put back — so this controller has to
        // work without them.
        if (this.hasSavedTarget) this.savedTarget.classList.add('d-none');
        if (this.hasUnsavedTarget) this.unsavedTarget.classList.add('d-none');

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
            control.removeAttribute('aria-invalid');
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

    // Marking the first refused answer is not the same as reaching it: a page
    // can be long, and its lists fold. So the caret goes there — to the control
    // itself, or, when it is the list that holds too few entries, to the button
    // that adds one.
    #stand(slot) {
        const item = slot.closest('[data-item]');

        if (item !== null) {
            item.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea')?.focus();

            return;
        }

        const list = slot.closest('[data-collection]');
        const add = [...(list?.querySelectorAll('[data-entries-target="add"]') ?? [])].find(
            (button) => button.closest('[data-collection]') === list && button.closest('template') === null,
        );

        add?.focus();
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
                const list = this.#ownLists(scope).find((candidate) => candidate.dataset.collection === step);

                // Not a list, so what follows is inside one value rather than
                // inside an entry: a multiple choice names the member it refused
                // (`/tags/1`). The message belongs on the control holding all of
                // them — it is the only thing on the page the person can be
                // shown.
                if (list === undefined) return this.#slotIn(scope, step);

                scope = list;
                continue;
            }

            return this.#slotIn(scope, step);
        }

        return null;
    }

    // What a refused answer is told to a person. The API's own message is
    // written for whoever is calling it — "Array should have at most 2 items, 3
    // found" is right in a log and no use to somebody who ticked one box too
    // many — so the page words the code itself and keeps that message for
    // anything it has no words for. `{n}` is the number the rule is about, and
    // the control is where that number already is.
    #refusalText(error, slot) {
        const name = slot.dataset.error;
        // Beside the slot, which is where the template puts both: a list
        // wrapper, or the control holding the value. Which of the two it is
        // decides the words — "at least 2 of these" is entries, "choose at least
        // 2" is ticks.
        const beside = slot.parentElement;
        const list = beside?.querySelector(`[data-collection="${name}"]`) ?? null;
        const carrier = list ?? beside?.querySelector(`[data-name="${name}"][data-type]`) ?? null;
        const words = this.refusalsValue[`${list ? 'list.' : ''}${error.code}`] ?? this.refusalsValue[error.code];

        if (words === undefined) return error.message;

        if (!words.includes('{n}')) return words;

        const number = this.#boundFor(error.code, carrier);

        return number === null ? error.message : words.replace('{n}', number);
    }

    // Which number a message about this rule is about, read off the thing that
    // carries it: the same attribute the browser itself enforces where it can.
    #boundFor(code, element) {
        if (element === null) return null;

        const held = {
            'schema.maxLength': element.getAttribute('maxlength'),
            'schema.minItems': element.dataset.min,
            'schema.maxItems': element.dataset.max,
            'schema.minimum': element.getAttribute('min'),
            'schema.maximum': element.getAttribute('max'),
            'schema.formatMinimum': element.dataset.momentMin ?? element.getAttribute('min'),
            'schema.formatMaximum': element.dataset.momentMax ?? element.getAttribute('max'),
        }[code];

        return held === undefined || held === null || held === '' ? null : held;
    }

    #slotIn(scope, name) {
        return this.errorTargets.find(
            (slot) => scope.contains(slot) && this.#listOwning(slot, scope) === null && slot.dataset.error === name,
        ) ?? null;
    }

    #showErrors(body) {
        const rest = [];
        let refused = null;

        for (const error of body.errors ?? []) {
            const slot = this.#slotFor(error.pointer ?? '');

            if (slot === null) {
                rest.push(error.message);
                continue;
            }

            slot.textContent = this.#refusalText(error, slot);
            slot.classList.remove('d-none');
            this.#reveal(slot);
            refused ??= slot;

            const entry = slot.closest('[data-entry]') ?? this.element;

            for (const control of this.controlTargets) {
                if (control.dataset.name === slot.dataset.error && (control.closest('[data-entry]') ?? this.element) === entry) {
                    control.classList.add('is-invalid');
                    // Red is one way of saying it, and it is the way that only
                    // works for somebody looking at the control.
                    control.setAttribute('aria-invalid', 'true');
                }
            }
        }

        if (refused !== null) this.#stand(refused);

        if (rest.length === 0 && (body.errors ?? []).length > 0) return;

        if (!this.hasProblemTarget) return;

        this.problemTextTarget.textContent = rest.join(' ') || body.detail || body.title || this.refusedValue;
        this.problemTarget.classList.remove('d-none');
    }
}
