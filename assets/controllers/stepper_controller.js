import { Controller } from '@hotwired/stimulus';

/**
 * The `stepper` control: a number with a button on each side, for a small count
 * somebody would rather click than type.
 *
 * The limits are the definition's — the renderer puts them on the input as
 * `min`, `max` and `step`, the same attributes the published schema states as
 * `minimum`, `maximum` and `multipleOf` — so this only ever moves the value
 * between them. Whether the value is acceptable is still the server's word.
 */
export default class extends Controller {
    static targets = ['input'];

    step(event) {
        const input = this.inputTarget;
        const by = Number(event.params.by) * (Number(input.step) || 1);
        const min = input.min === '' ? -Infinity : Number(input.min);
        const max = input.max === '' ? Infinity : Number(input.max);
        const from = input.value === '' ? (min === -Infinity ? 0 : min) : Number(input.value);

        input.value = Math.min(max, Math.max(min, from + by));
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
