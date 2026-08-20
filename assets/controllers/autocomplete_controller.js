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
 */
export default class extends Controller {
    connect() {
        this.select = new TomSelect(this.element, {
            allowEmptyOption: true,
            maxOptions: null,
            placeholder: this.element.dataset.placeholder ?? '',
        });
    }

    disconnect() {
        this.select?.destroy();
    }
}
