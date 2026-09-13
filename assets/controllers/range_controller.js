import { Controller } from '@hotwired/stimulus';

/**
 * The `range` control: a number chosen by sliding, with the number beside it.
 *
 * A slider says its answer by where the thumb sits, which is the one kind of
 * answer that cannot be read — not off the screen at any precision worth having,
 * and not off paper at all, since the track and the thumb are backgrounds a
 * printer is free to leave out. So the number is written next to it and kept in
 * step here.
 *
 * It is **not announced**: the range already carries `aria-valuenow`, so anybody
 * listening hears the value from the control itself, and an `<output>` that
 * repeated it would say everything twice on every step of a drag. This is a
 * reading for the eyes, which is why the markup marks it `aria-hidden`.
 */
export default class extends Controller {
    static targets = ['input', 'value'];

    connect() {
        this.show();
    }

    show() {
        this.valueTarget.textContent = this.inputTarget.value;
    }
}
