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
}
