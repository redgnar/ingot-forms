import { Controller } from '@hotwired/stimulus';
import { localReading } from '../moments.js';

/**
 * A moment, shown on the reader's own wall.
 *
 * `datetime-local` has no offset in it: it reads and writes what a clock in this
 * room says. The value and the bounds are moments, and turning one into the
 * other is something only the browser can do, because only the browser knows
 * which room it is in. So the server sends the moments in `data-moment*` and
 * this fills the control in.
 *
 * Stimulus connects a cloned entry's controls as well, which is why the bounds
 * of a list's date-times arrive without anybody arranging it.
 */
export default class extends Controller {
    connect() {
        this.element.min = localReading(this.element.dataset.momentMin);
        this.element.max = localReading(this.element.dataset.momentMax);
        this.element.value = localReading(this.element.dataset.moment);
    }

    // The picker a browser opens over this control has no way to be closed from
    // here — there is `showPicker()` and nothing to answer it — so the field is
    // let go of instead, which closes it. Only after a pointer opened it: on the
    // keyboard a value becomes complete half way through typing one, and taking
    // the focus away there would throw somebody out of the field mid-edit.
    pointer() {
        this.byPointer = true;
    }

    typed() {
        this.byPointer = false;
    }

    chosen() {
        if (!this.byPointer) return;

        this.byPointer = false;
        this.element.blur();
    }
}
