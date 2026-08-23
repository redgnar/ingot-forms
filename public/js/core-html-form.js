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
    if (unsaved) unsaved.hidden = true;

    for (const slot of document.querySelectorAll('[data-error]')) {
        slot.hidden = true;
        slot.textContent = '';
    }

    for (const control of document.querySelectorAll('[aria-invalid]')) {
        control.removeAttribute('aria-invalid');
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
    let refused = null;

    for (const error of body.errors ?? []) {
        const slot = slotFor(error.pointer ?? '');

        if (slot) {
            slot.textContent = error.message;
            slot.hidden = false;
            reveal(slot);
            // The message stands under the control; this is what says the control
            // is the one it is about, to anybody who cannot see where it stands.
            slot.closest('.item')?.querySelector('[data-name][data-type]')?.setAttribute('aria-invalid', 'true');
            refused ??= slot;
        } else {
            rest.push(error.message);
        }
    }

    if (refused !== null) stand(refused);

    if (rest.length === 0 && (body.errors ?? []).length > 0) return;

    const notice = document.getElementById('form-error');
    notice.textContent = rest.join(' ') || body.detail || body.title || document.body.dataset.refused;
    notice.hidden = false;
}

// Marking the first refused answer is not the same as reaching it: a page can be
// long, and its entries are answered in forms folded away. So the caret goes
// there — to the control itself, or, when it is the list that holds too few
// entries, to the button that adds one.
function stand(slot) {
    const item = slot.closest('.item');

    if (item !== null) {
        item.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea')?.focus();

        return;
    }

    const list = listOf(slot);

    if (list !== null) ownPart(list, '[data-action="add-entry"]')?.focus();
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

        if (await send('/data', 'PUT', collect())) {
            forgetUnsaved();
            asDrawn = JSON.stringify(collect());
            if (saved) saved.hidden = false;
            if (versions !== null && versions.open) loadVersions();
        }
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
// with the same group would unpick each other. What points at a caption or a
// message is the same kind of name, and pointing at another entry's is how a
// question comes to be read out twice and answered once. A list inside the entry
// keeps its own token for later, because only the first one is replaced here.
function claim(entry, pending, mine) {
    for (const element of entry.querySelectorAll('[id], [for], [name], [aria-labelledby], [aria-describedby]')) {
        for (const attribute of ['id', 'for', 'name', 'aria-labelledby', 'aria-describedby']) {
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

// One more entry, wherever it was asked for: by somebody pressing the button, or
// by a document being put back onto the page.
function addEntry(list, answering = false) {
    const blank = ownPart(list, 'template[data-blank]');

    if (blank === null) return;

    const added = blank.content.cloneNode(true);
    const entry = added.firstElementChild;
    claim(added, list.dataset.pending, `n${++claimed}`);
    // A new entry has nothing in its row yet, so the only thing to do with it is
    // answer it: it arrives unfolded.
    for (const form of added.querySelectorAll('details')) form.open = true;

    const foot = ownPart(list, '[data-entries-foot]');
    // Before the footer: a table's footer comes after its bodies.
    if (foot === null) {
        ownPart(list, 'table')?.append(added);
    } else {
        foot.before(added);
    }

    for (const nested of entry?.querySelectorAll('[data-collection]') ?? []) guard(nested);
    guard(list);

    // Somebody who asked for one more entry is about to answer it, and the form
    // they answer it in is below the row they pressed. A document being put back
    // onto the page asked for nothing, and moves nobody.
    if (answering) entry?.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea')?.focus();
}

document.getElementById('form').addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-action]');
    const list = listOf(trigger);

    if (trigger === null || list === null) return;

    if (trigger.dataset.action === 'add-entry') {
        event.preventDefault();
        addEntry(list, true);
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

        // The picker is the control the label points at, so it is the one that
        // has to say it was refused.
        const picker = control.querySelector('[data-upload]');

        if (picker !== null) {
            if (text === '') picker.removeAttribute('aria-invalid');
            else picker.setAttribute('aria-invalid', 'true');
        }
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

// The three things a reader can ask of this page: which colours, how much
// contrast, how big the text. The page already applied what it found in storage
// before this module ran — an inline script in the head, because a preference
// applied after the first paint is one the reader watches being applied. What is
// left is to show which one they are on, and to write a change down.
//
// Nothing reaches the server. This service has no idea who anybody is, so the
// browser is the only place a reading preference can live, and the right one: it
// is a fact about a screen and a pair of eyes rather than about a form.
const comfort = document.querySelector('[data-comfort]');

if (comfort !== null) {
    const root = document.documentElement;
    // Each switch is a plain on/off: the attribute it sets on <html>, what "on"
    // and "off" mean there, and what to remember it as. The stylesheet reads
    // those attributes and nothing else.
    const switches = {
        dark: { attribute: 'theme', on: 'dark', off: 'light', stash: 'theme' },
        contrast: { attribute: 'contrast', on: 'high', off: 'off', stash: 'contrast' },
        text: { attribute: 'text', on: 'large', off: 'off', stash: 'text' },
    };

    for (const box of comfort.querySelectorAll('[data-comfort-toggle]')) {
        const how = switches[box.dataset.comfortToggle];

        if (how === undefined) continue;

        box.checked = root.dataset[how.attribute] === how.on;
        box.addEventListener('change', () => {
            root.dataset[how.attribute] = box.checked ? how.on : how.off;

            try {
                // Written down either way: a reader whose machine asks for dark,
                // or whose document prefers it, and who turns it off here means
                // that, and must not be given it back by the next page.
                localStorage.setItem(`ingot-forms:${how.stash}`, box.checked ? how.on : how.off);
            } catch {
                // No storage to keep it in (a private window, storage turned
                // off): the choice holds for this page and is forgotten on the
                // next one.
            }
        });
    }
}

// Earlier versions: a list of moments and nothing else. What a save *said* is
// shown by the form itself — "View" is this same page drawn from that document —
// so nothing here lists values out of the only context that gives them meaning.
//
// Nothing is fetched until somebody opens the panel, and every row is a clone of
// a template the server rendered: this file moves markup, it does not write it.
const page = document.body.dataset.page;
const versions = document.querySelector('[data-history]');

if (versions !== null) {
    versions.addEventListener('toggle', () => {
        if (versions.open) loadVersions();
    });

    // A page drawn from an earlier save opens this panel itself: which moment you
    // are looking at, and what else there is, is the context of that page rather
    // than an aside to it. `toggle` never fires for a panel that arrived open.
    if (versions.open) loadVersions();
}

async function loadVersions() {
    const list = versions.querySelector('[data-history-list]');
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

    list.textContent = '';
    versions.querySelector('[data-history-error]').hidden = true;
    versions.querySelector('[data-history-empty]').hidden = revisions.length > 0;

    for (const revision of revisions) {
        const row = versions.querySelector('template[data-history-moment]').content.cloneNode(true);
        const when = row.querySelector('[data-history-when]');

        when.textContent = new Date(revision.savedAt).toLocaleString();
        when.dateTime = revision.savedAt;
        row.querySelector('[data-history-confirmed]').hidden = !revision.confirmed;
        // The page this form is drawn at, plus the version: the one place that
        // knows the shape of that address is the server, which put it on the body.
        row.querySelector('[data-history-view]').href = `${page}/versions/${revision.seq}`;

        const restore = row.querySelector('[data-history-restore]');
        if (restore !== null) restore.dataset.historyRestore = revision.seq;

        list.append(row);
    }
}

// The collector, backwards: putting a document onto the page the same way it is
// read off it. Used for one thing only — carrying what somebody typed across a
// look at an earlier version — and it walks the same structure, so a list inside a
// list comes back as one too.
function fill(scope, values) {
    for (const control of ownControls(scope)) {
        place(control, values[control.dataset.name] ?? null);
    }

    for (const list of ownLists(scope)) {
        const entries = Array.isArray(values[list.dataset.collection]) ? values[list.dataset.collection] : [];

        while (entriesOf(list).length > entries.length) entriesOf(list).at(-1).remove();

        // Bounded, and stops the moment adding one stops working: a page that
        // cannot grow a row must not take the browser down with it.
        while (entriesOf(list).length < entries.length) {
            const before = entriesOf(list).length;
            addEntry(list);

            if (entriesOf(list).length === before) break;
        }

        entriesOf(list).forEach((entry, index) => {
            fill(entry, entries[index]);
            refreshCells(entry);
        });

        guard(list);
    }
}

function place(control, value) {
    if (control.classList.contains('choice')) {
        for (const option of control.querySelectorAll('input')) {
            option.checked = value !== null && option.value === String(value);
        }
    } else if (control.type === 'checkbox') {
        control.checked = value === true;
    } else if (control.dataset.type === 'json') {
        // A file is a description plus the line that says which file is held.
        hold(control.closest('[data-file]'), value);
    } else {
        control.value = value === null ? '' : String(value);
    }
}

// Looking at an earlier version means leaving this page, and leaving a page
// throws away what nobody saved. So what is on it goes with you: kept for the
// length of the detour, in this tab only, and taken back the moment you return.
// Anything that settles the question — a save, a restore, starting again — drops
// it, because then there is nothing unsaved to carry.
const unsaved = document.getElementById('form-unsaved');
const stash = `ingot-forms:unsaved:${formId}`;
// What the server drew: anything else on the page is somebody's unsaved doing.
let asDrawn = null;

function keepUnsaved() {
    const now = JSON.stringify(collect());

    // Nothing was typed, so there is nothing to carry — and saying "these are not
    // saved" about the form's own values would be a lie.
    if (now === asDrawn) {
        forgetUnsaved();

        return;
    }

    try {
        sessionStorage.setItem(stash, now);
    } catch {
        // No storage to keep it in (a private window, storage turned off): the
        // detour costs what it used to cost, and nothing else breaks.
    }
}

function takeUnsaved() {
    try {
        const kept = sessionStorage.getItem(stash);
        sessionStorage.removeItem(stash);

        return kept === null ? null : JSON.parse(kept);
    } catch {
        return null;
    }
}

function forgetUnsaved() {
    try {
        sessionStorage.removeItem(stash);
    } catch {
        // Nothing was kept, so nothing is left behind.
    }
}

// Back on the page somebody was typing in: put their answers back and say that
// they are still not saved, because a page that shows something other than what
// the server holds has to admit it.
if (document.body.dataset.version === undefined) {
    const kept = takeUnsaved();

    if (kept !== null) {
        fill(document.getElementById('form'), kept);
        if (unsaved) unsaved.hidden = false;
    }

    // Whatever the page holds now — drawn by the server, or drawn and then filled
    // back in — is the baseline the next detour is measured against.
    asDrawn = JSON.stringify(collect());
}
// On a version page, nothing is touched: what somebody typed is waiting for them
// to come back, and this page is the middle of that trip rather than the end.

// Putting a version back is an ordinary draft save of a document this page
// happens to have read — the same gates, the same refusals. Afterwards the form
// is drawn again by the server, because every control on it has just changed.
async function restoreVersion(seq) {
    forgetUnsaved();
    const response = await fetch(`/api/forms/${formId}/history/${seq}`);

    if (response.ok && (await send('/data', 'PUT', await response.json()))) window.location.assign(page);
}

document.addEventListener('click', (event) => {
    const restore = event.target.closest('[data-history-restore]');

    if (restore !== null && restore.dataset.historyRestore !== '') {
        event.preventDefault();
        restoreVersion(restore.dataset.historyRestore);

        return;
    }

    // Back to what the form holds — which is what a page drawn again shows, so
    // there is nothing to undo by hand and nothing to get wrong.
    if (event.target.closest('[data-action="reset"]') !== null) {
        event.preventDefault();
        forgetUnsaved();
        window.location.assign(page);
    }

    // The two links that lead away from this page and back: an earlier version,
    // and the same page in another language. Both are detours, and what somebody
    // typed goes with them.
    if (event.target.closest('[data-history-view], [data-language]') !== null) keepUnsaved();
});

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
