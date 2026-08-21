import { Controller } from '@hotwired/stimulus';

/**
 * A list of entries: one more, one fewer, and a table that keeps up with the
 * forms under it.
 *
 * The blank entry it clones is rendered by the server and waits in a
 * `<template>`, so this never builds markup — it only moves it. What may
 * actually be stored is still the server's word; the guards here just stop the
 * obvious, which is what `min` and `max` are for.
 */
export default class extends Controller {
    static targets = ['table', 'blank', 'add', 'remove', 'foot'];
    static values = {
        min: { type: Number, default: 0 },
        max: { type: Number, default: Number.MAX_SAFE_INTEGER },
        pending: String,
        ticked: String,
        unticked: String,
    };

    connect() {
        this.claimed = 0;
        this.#guard();
    }

    add(event) {
        event.preventDefault();

        const added = this.blankTarget.content.cloneNode(true);

        this.#claim(added, `n${++this.claimed}`);

        // A new entry has nothing in its row yet, so the only thing to do with
        // it is answer it: it arrives unfolded.
        for (const form of added.querySelectorAll('details')) form.open = true;

        // Before the footer: a table's footer comes after its bodies.
        if (this.hasFootTarget) {
            this.footTarget.before(added);
        } else {
            this.tableTarget.append(added);
        }

        this.#guard();
    }

    // A blank entry has no place in the list, so the server left a token where
    // an entry's own scope would be. Replacing it is what makes a cloned entry
    // its own: an id names one thing, and radios sharing a name are one group —
    // two entries with the same group would unpick each other.
    #claim(entry, mine) {
        for (const element of entry.querySelectorAll('[id], [for], [name]')) {
            for (const attribute of ['id', 'for', 'name']) {
                const value = element.getAttribute(attribute);

                if (value !== null && value.includes(this.pendingValue)) {
                    element.setAttribute(attribute, value.replace(this.pendingValue, mine));
                }
            }
        }
    }

    remove(event) {
        event.preventDefault();
        event.target.closest('[data-entry]').remove();
        this.#guard();
    }

    // A table that contradicts the form under it is worse than no table.
    touched(event) {
        const entry = event.target.closest('[data-entry]');

        if (entry !== null) this.#refresh(entry);
    }

    #entries() {
        return [...this.element.querySelectorAll('[data-entry]')].filter(
            (entry) => entry.closest('[data-collection]') === this.element && entry.closest('template') === null,
        );
    }

    #guard() {
        const count = this.#entries().length;

        for (const button of this.addTargets) button.disabled = count >= this.maxValue;
        for (const button of this.removeTargets) button.disabled = count <= this.minValue;
    }

    #refresh(entry) {
        for (const cell of entry.querySelectorAll('[data-cell]')) {
            const control = [...entry.querySelectorAll(`[data-name="${cell.dataset.cell}"]`)].find(
                (candidate) => candidate.closest('[data-entry]') === entry,
            );

            if (control === undefined) continue;

            cell.textContent = this.#reads(control);
        }
    }

    #reads(control) {
        if (control.dataset.type === 'json') {
            // A file reads as what it is called: the only part of a description
            // that means anything to a person.
            return control.value === '' ? '' : JSON.parse(control.value).name;
        }

        if (control.dataset.choice !== undefined) {
            const picked = control.querySelector('input:checked');

            return picked === null ? '' : (picked.labels?.[0]?.textContent.trim() ?? picked.value);
        }

        if (control.type === 'checkbox') {
            return control.checked ? this.tickedValue : this.untickedValue;
        }

        if (control.tagName === 'SELECT') {
            return control.selectedOptions[0]?.textContent.trim() ?? '';
        }

        return control.value;
    }
}
