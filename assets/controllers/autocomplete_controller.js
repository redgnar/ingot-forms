import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * The `autocomplete` control: a select somebody can search, for a choice too
 * long to scroll. This is the widget `core-html` has no answer for — which is
 * the whole reason a presentation names the kit it was written for.
 *
 * TomSelect keeps the original `<select>` in the page and writes the picked
 * value back into it, so the form controller collects it like any other control
 * and the API sees nothing unusual.
 *
 * It draws a multiple choice as well, and nothing here is told which: the
 * element says `multiple` because the item does, and that is the one place that
 * fact lives. Picked options become chips with a way to take each one off,
 * because a chip somebody cannot remove is an answer they cannot change.
 */
export default class extends Controller {
    connect() {
        const many = this.element.multiple;

        this.select = new TomSelect(this.element, {
            // An empty option is how a single choice says "none"; a multiple
            // choice says it by having nothing picked.
            allowEmptyOption: !many,
            maxOptions: null,
            placeholder: this.element.dataset.placeholder ?? '',
            plugins: many ? ['remove_button'] : [],
            // The ceiling the item states, kept by the library that draws the
            // chips — the same reason a group of ticks keeps its own: a page
            // that lets somebody past it has produced a state its own save
            // refuses. Undefined leaves the library's own default (no limit).
            maxItems: many ? Number(this.element.dataset.max) || null : 1,
        });
    }

    disconnect() {
        this.select?.destroy();
    }
}
