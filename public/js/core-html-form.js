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

        // A file's value is a whole document — the description the upload
        // answered with — carried in a hidden control as the JSON it is.
        values[name] = type === 'json' ? JSON.parse(raw) : type === 'number' ? Number(raw) : raw;
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

    for (const entry of document.querySelectorAll('[data-entry].entry-invalid')) {
        entry.classList.remove('entry-invalid');
    }
}

// A message nobody can see is not a message. An entry is answered in a form that
// is folded away, so a refusal about it unfolds every form on the way to it — and
// marks each entry it is inside, so the row still says "look here" once somebody
// folds it back up.
function reveal(slot) {
    for (let form = slot.closest('details'); form !== null; form = form.parentElement?.closest('details') ?? null) {
        form.open = true;
    }

    for (let entry = slot.closest('[data-entry]'); entry !== null; entry = entry.parentElement?.closest('[data-entry]') ?? null) {
        entry.classList.add('entry-invalid');
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
            reveal(slot);
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
            const list = listOf(cell);
            cell.textContent = control.checked ? list.dataset.ticked : list.dataset.unticked;
            continue;
        }

        if (control.tagName === 'SELECT') {
            cell.textContent = control.selectedOptions[0]?.textContent.trim() ?? '';
            continue;
        }

        if (control.dataset.type === 'json') {
            cell.textContent = control.value === '' ? '' : JSON.parse(control.value).name;
            continue;
        }

        cell.textContent = control.value;
    }
}

// Which list something belongs to — its own, never one nested inside it.
function listOf(element) {
    return element?.closest('[data-collection]') ?? null;
}

function ownPart(list, selector) {
    return [...list.querySelectorAll(selector)].find((found) => listOf(found) === list) ?? null;
}

function guard(list) {
    const count = entriesOf(list).length;
    const min = list.dataset.min === undefined ? 0 : Number(list.dataset.min);
    const max = list.dataset.max === undefined ? Infinity : Number(list.dataset.max);

    for (const button of list.querySelectorAll('[data-action="add-entry"]')) {
        if (listOf(button) === list && button.closest('template') === null) button.disabled = count >= max;
    }

    for (const button of list.querySelectorAll('[data-action="remove-entry"]')) {
        if (listOf(button) === list && button.closest('template') === null) button.disabled = count <= min;
    }
}

// A blank entry has no place in the list, so the server left a token where an
// entry's own scope would be. Replacing it is what makes a cloned entry its own:
// an id names one thing, and radios sharing a name are one group — two entries
// with the same group would unpick each other. A list inside the entry keeps its
// own token for later, because only the first one is replaced here.
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

// Delegated from the form, not bound per list: a list can arrive with a cloned
// entry — a list inside a list is drawn the same way — and anything bound at
// load would not know about it.
let claimed = 0;

document.getElementById('form').addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-action]');
    const list = listOf(trigger);

    if (trigger === null || list === null) return;

    if (trigger.dataset.action === 'add-entry') {
        event.preventDefault();
        const blank = ownPart(list, 'template[data-blank]');

        if (blank !== null) {
            const added = blank.content.cloneNode(true);
            claim(added, list.dataset.pending, `n${++claimed}`);
            // A new entry has nothing in its row yet, so the only thing to do
            // with it is answer it: it arrives unfolded.
            for (const form of added.querySelectorAll('details')) form.open = true;

            const foot = ownPart(list, '[data-entries-foot]');
            // Before the footer: a table's footer comes after its bodies.
            if (foot === null) {
                ownPart(list, 'table')?.append(added);
            } else {
                foot.before(added);
            }

            for (const nested of added.querySelectorAll?.('[data-collection]') ?? []) guard(nested);
        }
    }

    if (trigger.dataset.action === 'remove-entry') {
        event.preventDefault();
        trigger.closest('[data-entry]').remove();
    }

    guard(list);
});

document.getElementById('form').addEventListener('input', (event) => {
    const entry = event.target.closest('[data-entry]');

    if (entry !== null) refreshCells(entry);
});

for (const list of document.querySelectorAll('[data-collection]')) guard(list);

// A file is not typed into a control: it is uploaded, and what the values
// document holds afterwards is the description the upload answered with. So the
// picker is only how somebody chooses bytes — nothing collects it — and the
// hidden control beside it is the value.
async function upload(picker) {
    const control = picker.closest('[data-file]');
    const file = picker.files?.[0];

    if (control === null || !file) return;

    const said = document.body.dataset;

    // Bigger than the form allows: the answer is known here, so nothing is sent.
    if (file.size > Number(control.dataset.maxSize)) {
        picker.value = '';
        say(control, said.fileTooLarge);

        return;
    }

    say(control, said.fileUploading, 'progress');

    const body = new FormData();
    body.append('file', file);

    let reference = null;

    try {
        const response = await fetch(`/api/forms/${formId}/files`, { method: 'POST', body });

        if (response.ok) reference = await response.json();
        else say(control, (await problem(response)).detail || said.fileFailed);
    } catch {
        say(control, said.fileFailed);
    }

    picker.value = '';

    if (reference === null) return;

    // What kind of bytes those are is the server's word, not the browser's — so
    // the item's own rule is checked against what came back. Nothing names the
    // file yet, so a refusal can take it away at once.
    const accepted = (control.dataset.accept ?? '').split(',').filter((type) => type !== '');

    if (accepted.length > 0 && !accepted.includes(reference.type)) {
        await discard(reference.id);
        say(control, said.fileRejected);

        return;
    }

    hold(control, reference);
}

// Filling in markup the server rendered, never writing markup here: the line
// that says which file is held is on the page already, waiting.
function hold(control, reference) {
    const held = control.querySelector('[data-type="json"]');
    const line = control.querySelector('[data-file-held]');
    const link = control.querySelector('[data-file-download]');

    held.value = reference === null ? '' : JSON.stringify(reference);

    if (reference !== null) {
        link.textContent = reference.name;
        link.href = `/api/forms/${formId}/files/${reference.id}`;
    }

    line.hidden = reference === null;
    say(control, '');

    const entry = control.closest('[data-entry]');
    if (entry !== null) refreshCells(entry);
}

// Taking back what nothing names yet. A refusal here is not this page's
// business: 409 means a stored document still names it, and then the next save
// is what drops it — and the save is what throws it away.
async function discard(file) {
    try {
        await fetch(`/api/forms/${formId}/files/${file}`, { method: 'DELETE' });
    } catch {
        // The file stays temporary, and the collector takes it later.
    }
}

// One place for anything a file control has to say, in the page's own words.
function say(control, text, kind = 'error') {
    const progress = control.querySelector('[data-file-progress]');
    const slot = control.closest('.item')?.querySelector('[data-error]');

    if (progress !== null) {
        progress.textContent = kind === 'progress' ? text : '';
        progress.hidden = kind !== 'progress' || text === '';
    }

    if (slot && kind === 'error') {
        slot.textContent = text;
        slot.hidden = text === '';
    }
}

document.getElementById('form').addEventListener('change', (event) => {
    if (event.target.matches('[data-upload]')) upload(event.target);
});

document.getElementById('form').addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-action="remove-file"]');

    if (trigger === null) return;

    event.preventDefault();
    const control = trigger.closest('[data-file]');
    const held = control.querySelector('[data-type="json"]');
    const reference = held.value === '' ? null : JSON.parse(held.value);

    hold(control, null);

    if (reference !== null) discard(reference.id);
});

// Earlier versions. Nothing is fetched until somebody opens the panel, and every
// row drawn below is a clone of a template the server rendered — this file moves
// markup, it does not write it.
const versions = document.querySelector('[data-history]');

if (versions !== null) {
    versions.addEventListener('toggle', () => {
        if (versions.open && versions.dataset.loaded === undefined) loadHistory();
    });
}

async function loadHistory() {
    versions.dataset.loaded = 'yes';
    const list = versions.querySelector('[data-history-list]');
    list.textContent = '';

    let revisions = null;

    try {
        const response = await fetch(`/api/forms/${formId}/history`);
        if (response.ok) revisions = (await response.json()).revisions ?? [];
    } catch {
        revisions = null;
    }

    if (revisions === null) {
        versions.querySelector('[data-history-error]').hidden = false;

        return;
    }

    versions.querySelector('[data-history-empty]').hidden = revisions.length > 0;

    for (const revision of revisions) {
        const row = versions.querySelector('template[data-history-moment]').content.cloneNode(true);
        const when = row.querySelector('[data-history-when]');

        when.textContent = new Date(revision.savedAt).toLocaleString();
        when.dateTime = revision.savedAt;
        row.querySelector('[data-history-open]').dataset.historyOpen = revision.seq;
        row.querySelector('[data-history-confirmed]').hidden = !revision.confirmed;
        list.append(row);
    }
}

// One earlier version, member by member: what it held, and — for the answers a
// single control holds — a way to put just that one back.
async function showVersion(seq) {
    const response = await fetch(`/api/forms/${formId}/history/${seq}`);

    if (!response.ok) {
        versions.querySelector('[data-history-error]').hidden = false;

        return;
    }

    const version = await response.json();
    const members = versions.querySelector('[data-history-members]');
    members.textContent = '';
    versions.querySelector('[data-history-version]').hidden = false;
    versions.querySelector('[data-history-version]').dataset.historyVersion = seq;

    for (const [name, value] of Object.entries(version)) {
        const row = versions.querySelector('template[data-history-member]').content.cloneNode(true);
        row.querySelector('[data-history-name]').textContent = name;
        row.querySelector('[data-history-value]').textContent = reads(value);

        const put = row.querySelector('[data-history-put]');

        // Only what one control holds. A list is many controls and a file is a
        // description with a chip beside it — those two go back with the whole
        // version, which is the button above.
        if (put !== null && controlFor(name) !== null && (value === null || typeof value !== 'object')) {
            put.dataset.historyPut = name;
            put.hidden = false;
        }

        members.append(row);
    }
}

function reads(value) {
    if (value === null || typeof value !== 'object') return String(value);
    // A file reads as what it is called, like it does in a list's own row.
    if (typeof value.name === 'string' && typeof value.id === 'string') return value.name;

    return JSON.stringify(value);
}

// The control that holds this answer at the top of the form — never one inside an
// entry, because a member of a list is answered once per entry.
function controlFor(name) {
    return ownControls(document.getElementById('form')).find((control) => control.dataset.name === name) ?? null;
}

// The collector, backwards: what a control holds is written the way it is read.
function place(control, value) {
    if (control.classList.contains('choice')) {
        for (const option of control.querySelectorAll('input')) option.checked = option.value === String(value);
    } else if (control.type === 'checkbox') {
        control.checked = Boolean(value);
    } else {
        control.value = String(value);
    }

    control.dispatchEvent(new Event('input', { bubbles: true }));
}

if (versions !== null) {
    versions.addEventListener('click', async (event) => {
        const moment = event.target.closest('[data-history-open]');

        if (moment !== null) {
            event.preventDefault();
            await showVersion(moment.dataset.historyOpen);

            return;
        }

        const put = event.target.closest('[data-history-put]');

        if (put !== null) {
            event.preventDefault();
            const response = await fetch(`/api/forms/${formId}/history/${versions.querySelector('[data-history-version]').dataset.historyVersion}`);
            const version = await response.json();
            const control = controlFor(put.dataset.historyPut);

            // Written into the control rather than sent: somebody puts an answer
            // back, looks at the form, and saves when they mean to.
            if (control !== null) place(control, version[put.dataset.historyPut]);

            return;
        }

        const whole = event.target.closest('[data-history-restore]');

        if (whole !== null) {
            event.preventDefault();
            const seq = versions.querySelector('[data-history-version]').dataset.historyVersion;
            const response = await fetch(`/api/forms/${formId}/history/${seq}`);

            // An ordinary draft save of a document this page happens to have read
            // — the same gates, the same refusals. The page is drawn again by the
            // server, because every control on it has just changed.
            if (response.ok && (await send('/data', 'PUT', await response.json()))) window.location.reload();
        }
    });
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
