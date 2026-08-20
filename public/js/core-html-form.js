// The page is a client of this service's own API, with no privileged path of its
// own: it builds the values document, sends it, and shows what comes back.
//
// Types come from the definition by way of the renderer (`data-type`), because a
// control only ever holds text and the contract asks for JSON — a number is a
// number and a tick is `true`.

const formId = document.body.dataset.form;
const problem = (response) => response.json().catch(() => ({}));
const saved = document.getElementById('form-saved');

function collect() {
    const values = {};

    for (const control of document.querySelectorAll('[data-name][data-type]')) {
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

    return values;
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

// A refusal points at the item it is about (`/email`), so each message lands
// beside the control it belongs to; anything else is shown as one notice.
function showErrors(body) {
    const rest = [];

    for (const error of body.errors ?? []) {
        const slot = document.querySelector(`[data-error="${(error.pointer ?? '').replace(/^\//, '')}"]`);

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
