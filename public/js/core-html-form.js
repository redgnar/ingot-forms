// The page is a client of this service's own API, with no privileged path of its
// own: it builds the values document, sends it, and shows what comes back.
//
// Types come from the definition by way of the renderer (`data-type`), because a
// control only ever holds text and the contract asks for JSON — a number is a
// number and a tick is `true`.

const formId = document.body.dataset.form;
const problem = (response) => response.json().catch(() => ({}));
const saved = document.getElementById('form-saved');

// Structure carries identity: what a control answers is read from where it
// sits, so a list's entries are collected in the order they appear and nothing
// has to be renumbered when one is added or removed.
function collect(scope = document.getElementById('form')) {
    const values = {};

    for (const control of ownControls(scope)) {
        const name = control.dataset.name;
        const type = control.dataset.type;

        if (control.classList.contains('choice')) {
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

        values[name] = type === 'number' ? Number(raw) : raw;
    }

    for (const list of ownLists(scope)) {
        values[list.dataset.collection] = entriesOf(list).map((entry) => collect(entry));
    }

    return values;
}

// Everything belonging to this scope rather than to a list inside it. A blank
// entry kept aside for cloning belongs to nobody until it is added.
function ownControls(scope) {
    return [...scope.querySelectorAll('[data-name][data-type]')].filter((control) => listOwning(control, scope) === null);
}

function ownLists(scope) {
    return [...scope.querySelectorAll('[data-collection]')].filter(
        (list) => listOwning(list.parentElement, scope) === null && list.closest('template') === null,
    );
}

function listOwning(element, scope) {
    const found = element?.closest('[data-collection]');

    return found && found !== scope && scope.contains(found) ? found : null;
}

function entriesOf(list) {
    return [...list.querySelectorAll('[data-entry]')].filter(
        (entry) => entry.closest('[data-collection]') === list && entry.closest('template') === null,
    );
}

// Everything the last attempt said about this form — a refusal beside a control,
// a notice, the confirmation that it was stored.
function clearMessages() {
    document.getElementById('form-error').hidden = true;
    if (saved) saved.hidden = true;

    for (const slot of document.querySelectorAll('[data-error]')) {
        slot.hidden = true;
        slot.textContent = '';
    }
}

// A refusal points at the item it is about — `/email`, or `/lines/2/quantity`
// for the third entry of a list — so the pointer is walked the same way the
// values were collected, and the message lands beside the control it belongs to.
function slotFor(pointer) {
    const steps = pointer.split('/').filter((step) => step !== '');
    let scope = document.getElementById('form');

    while (steps.length > 0) {
        const step = steps.shift();

        if (/^\d+$/.test(step)) {
            scope = entriesOf(scope)[Number(step)];
            if (!scope) return null;
            continue;
        }

        if (steps.length > 0) {
            scope = ownLists(scope).find((list) => list.dataset.collection === step);
            if (!scope) return null;
            continue;
        }

        return [...scope.querySelectorAll(`[data-error="${step}"]`)].find(
            (slot) => listOwning(slot, scope) === null,
        ) ?? null;
    }

    return null;
}

function showErrors(body) {
    const rest = [];

    for (const error of body.errors ?? []) {
        const slot = slotFor(error.pointer ?? '');

        if (slot) {
            slot.textContent = error.message;
            slot.hidden = false;
        } else {
            rest.push(error.message);
        }
    }

    if (rest.length === 0 && (body.errors ?? []).length > 0) return;

    const notice = document.getElementById('form-error');
    notice.textContent = rest.join(' ') || body.detail || body.title || document.body.dataset.refused;
    notice.hidden = false;
}

async function send(path, method, body) {
    clearMessages();

    const response = await fetch(`/api/forms/${formId}${path}`, {
        method,
        headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.ok) return true;

    showErrors(await problem(response));

    return false;
}

// Triggers are wherever the presentation put them, and there may be several of
// each: they are bound by what they do, not by where they are.
for (const trigger of document.querySelectorAll('[data-action="save"]')) {
    trigger.addEventListener('click', async (event) => {
        event.preventDefault();

        if (await send('/data', 'PUT', collect()) && saved) saved.hidden = false;
    });
}

// The notice says that what is on the page is what the form holds, so it lasts
// exactly as long as that is true: the next attempt clears it, and so does the
// first thing somebody changes after it.
document.getElementById('form').addEventListener('input', () => {
    if (saved) saved.hidden = true;
});

// A list: one more entry, one fewer, and cells that keep up with the form under
// them so the table never shows something its own form contradicts. What may be
// stored is still the server's word; these buttons only stop the obvious.
function refreshCells(entry) {
    for (const cell of entry.querySelectorAll('[data-cell]')) {
        const control = ownControls(entry).find((candidate) => candidate.dataset.name === cell.dataset.cell);

        if (!control) continue;

        if (control.classList.contains('choice')) {
            const picked = control.querySelector('input:checked');
            cell.textContent = picked ? picked.labels?.[0]?.textContent.trim() ?? picked.value : '';
            continue;
        }

        if (control.type === 'checkbox') {
            const list = cell.closest('[data-collection]');
            cell.textContent = control.checked ? list.dataset.ticked : list.dataset.unticked;
            continue;
        }

        if (control.tagName === 'SELECT') {
            cell.textContent = control.selectedOptions[0]?.textContent.trim() ?? '';
            continue;
        }

        cell.textContent = control.value;
    }
}

function guard(list) {
    const count = entriesOf(list).length;
    const min = list.dataset.min === undefined ? 0 : Number(list.dataset.min);
    const max = list.dataset.max === undefined ? Infinity : Number(list.dataset.max);

    for (const button of list.querySelectorAll('[data-action="add-entry"]')) {
        if (button.closest('template') === null) button.disabled = count >= max;
    }

    for (const entry of entriesOf(list)) {
        for (const button of entry.querySelectorAll('[data-action="remove-entry"]')) {
            button.disabled = count <= min;
        }
    }
}

// A blank entry has no place in the list, so the server left a token where an
// entry's own scope would be. Replacing it is what makes a cloned entry its own:
// an id names one thing, and radios sharing a name are one group — two entries
// with the same group would unpick each other.
function claim(entry, pending, mine) {
    for (const element of entry.querySelectorAll('[id], [for], [name]')) {
        for (const attribute of ['id', 'for', 'name']) {
            const value = element.getAttribute(attribute);

            if (value !== null && value.includes(pending)) {
                element.setAttribute(attribute, value.replace(pending, mine));
            }
        }
    }
}

for (const list of document.querySelectorAll('[data-collection]')) {
    const table = list.querySelector('table');
    const blank = list.querySelector('template[data-blank]');
    let claimed = 0;

    list.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-action]');
        if (!trigger || trigger.closest('[data-collection]') !== list) return;

        if (trigger.dataset.action === 'add-entry' && blank) {
            event.preventDefault();
            const added = blank.content.cloneNode(true);
            claim(added, list.dataset.pending, `n${++claimed}`);
            // A new entry has nothing in its row yet, so the only thing to do
            // with it is answer it: it arrives unfolded.
            for (const form of added.querySelectorAll('details')) form.open = true;
            table.append(added);
        }

        if (trigger.dataset.action === 'remove-entry') {
            event.preventDefault();
            trigger.closest('[data-entry]').remove();
        }

        guard(list);
    });

    list.addEventListener('input', (event) => {
        const entry = event.target.closest('[data-entry]');
        if (entry && entry.closest('[data-collection]') === list) refreshCells(entry);
    });

    guard(list);
}

for (const trigger of document.querySelectorAll('[data-action="confirm"]')) {
    trigger.addEventListener('click', async (event) => {
        event.preventDefault();

        // Confirming judges what the form holds, so what is on the page has to
        // be in it first.
        if (await send('/data', 'PUT', collect())) {
            if (await send('/confirm', 'POST')) window.location.reload();
        }
    });
}
