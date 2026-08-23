import { Controller } from '@hotwired/stimulus';

/**
 * Earlier versions of this form: a list of moments, and a way into each of them.
 *
 * What a save *said* is not listed here. Values out of the form they belong to say
 * nothing, so "View" is a link to this same page drawn from that document —
 * read-only, every control, list and attached file where it was. Putting one back
 * is the form controller's doing, because it is an ordinary draft save.
 *
 * Nothing is fetched until somebody opens the panel: a page nobody looks into
 * should not pay for it. Every row is a clone of a template the server rendered,
 * so this controller moves markup rather than writing it.
 */
export default class extends Controller {
    static values = { id: String, page: String };
    static targets = ['list', 'empty', 'failed', 'moment'];

    connect() {
        // A page drawn from an earlier save opens this panel itself: which moment
        // you are looking at, and what else there is, is the context of that page
        // rather than an aside to it. `toggle` never fires for a panel that
        // arrived open, so the list is asked for here.
        if (this.element.open) this.load();
    }

    opened() {
        if (this.element.open) this.load();
    }

    // Also called from outside, after a save: a new moment appeared, and a list
    // that does not show it is lying.
    async load() {
        let revisions = null;

        try {
            const response = await fetch(`/api/forms/${this.idValue}/history`);

            if (response.ok) revisions = (await response.json()).revisions ?? [];
        } catch {
            revisions = null;
        }

        if (revisions === null) {
            this.failedTarget.classList.remove('d-none');

            return;
        }

        this.listTarget.textContent = '';
        this.failedTarget.classList.add('d-none');
        this.emptyTarget.classList.toggle('d-none', revisions.length > 0);

        for (const revision of revisions) {
            const row = this.momentTarget.content.cloneNode(true);
            const when = row.querySelector('[data-history-when]');

            when.textContent = new Date(revision.savedAt).toLocaleString();
            when.dateTime = revision.savedAt;
            row.querySelector('[data-history-confirmed]').classList.toggle('d-none', !revision.confirmed);
            // The page this form is drawn at, plus the version: the one place that
            // knows the shape of that address is the server, which handed it over.
            row.querySelector('[data-history-view]').href = `${this.pageValue}/versions/${revision.seq}`;

            const restore = row.querySelector('[data-history-restore]');

            if (restore !== null) restore.dataset.historyRestore = revision.seq;

            this.listTarget.append(row);
        }
    }
}
