import { Controller } from '@hotwired/stimulus';
import SignaturePad from 'signature_pad';

/**
 * The `signature` control: somewhere to draw a signature, whose value is an
 * ordinary file.
 *
 * It knows nothing about uploading. When a stroke ends it turns the canvas into
 * a PNG and hands it to the file controller beside it (`file:pick`), which does
 * what it does for a picked file — sends it, checks that what came back is a type
 * the item accepts, shows the refusal if not, and holds the description that is
 * the actual value. So a signature costs no new address, no new gate and no new
 * kind of value: it is a third way of *asking* for a file.
 *
 * The pad is never the only way to answer. Nobody can draw with a keyboard, and
 * a question that cannot be answered without a mouse is a question this page has
 * no business asking — so the ordinary picker stays underneath, always, and
 * attaching a photograph of a signature is as good an answer as drawing one.
 */
export default class extends Controller {
    static targets = ['pad', 'frame', 'other', 'chooser', 'preview', 'redo'];

    connect() {
        this.mine = false;
        this.pad = new SignaturePad(this.padTarget, {
            // Nothing about the ink is a document's business, and nothing here
            // is a skin: a signature is black on white wherever it is drawn,
            // because that is what it will be looked at as.
            penColor: '#111111',
            backgroundColor: '#ffffff',
        });

        // A canvas has two sizes — the box it occupies and the grid it draws on
        // — and a signature drawn on the wrong one is a blurred signature. The
        // grid follows the box and the screen it is on.
        this.fit = () => this.#fit();
        window.addEventListener('resize', this.fit);
        this.#fit();

        this.pad.addEventListener('endStroke', () => this.#hand());
    }

    disconnect() {
        window.removeEventListener('resize', this.fit);
        this.pad?.off();
    }

    /**
     * Starting again — the same act whether the pad is showing or the signature
     * is. The file that was uploaded is left to the file controller to forget: it
     * owns the value and knows whether anything can still be taken away, and
     * what it answers with is what puts the pad back ({@see shows()}).
     */
    again(event) {
        event.preventDefault();
        this.pad.clear();
        this.dispatch('cleared', { prefix: 'file' });
    }

    /**
     * What this form holds, shown. A signature is an image, so naming the file
     * and drawing nothing answers the wrong question — and a form opened again
     * after a save would otherwise show an empty pad beside a filename.
     *
     * Both directions are here, which is why nothing else has to decide: a
     * description means the signature is shown and the pad is put away, and
     * nothing means the pad comes back to be drawn on.
     */
    shows(event) {
        const held = event.detail;

        // Not for the pad's own doing. A signature is often more than one stroke
        // — an initial and a surname, a dot over an i — and each of them lands as
        // an upload, so swapping the pad for a picture the moment a stroke ended
        // would take the pad away in the middle of signing.
        if (this.mine) {
            this.mine = false;

            return;
        }

        if (held !== null && held !== undefined) {
            this.previewTarget.src = held.href;
            this.#showing(true);

            return;
        }

        // Nothing held: the pad, empty, and the image out of the way rather than
        // left pointing at bytes that have just been thrown away.
        this.previewTarget.removeAttribute('src');
        this.#showing(false);
    }

    #showing(held) {
        this.previewTarget.hidden = !held;
        if (this.hasRedoTarget) this.redoTarget.hidden = !held;
        if (this.hasFrameTarget) this.frameTarget.hidden = held;
        if (this.hasOtherTarget) this.otherTarget.hidden = held;
        // The picker is the *other* way of answering, so it goes away with the
        // pad: what a form holding a signature offers is the signature and a way
        // to sign again, and pressing that brings both ways back.
        if (this.hasChooserTarget) this.chooserTarget.hidden = held;

        // A pad that was hidden while the window changed size measured nothing,
        // so it is measured now — otherwise the first stroke after coming back
        // lands somewhere else entirely.
        if (!held) this.#fit();
    }

    #hand() {
        // No "is it empty?" question here, deliberately. This runs when a stroke
        // has just ended, so there is ink by definition — and asking the library
        // instead was a bug: resizing the pad clears the canvas and puts the
        // strokes back, and its own empty-flag does not survive that. A pad that
        // had been resized once — a scrollbar appearing is a resize — would then
        // refuse to hand anything over, with the signature plainly visible on it.

        // Busy from the instant the stroke ended, not from the instant the upload
        // starts. Turning a canvas into a PNG is asynchronous, and a save pressed
        // in that gap would have found the file widget idle and its hidden control
        // empty — so the answer would have been left out of the document with
        // nothing to say it had been. The promise is settled *with* the upload's
        // own, so anybody already waiting on this one waits for the bytes to
        // land ({@see file_controller.js}, {@see form_controller.js}).
        const widget = this.element.closest('[data-controller~="file"]');
        let landed = null;
        // Ours, so that what comes back does not put the pad away
        // ({@see shows()}).
        this.mine = true;

        if (widget !== null) widget.filePending = new Promise((settled) => { landed = settled; });

        this.padTarget.toBlob((blob) => {
            if (blob === null) {
                landed?.(null);

                return;
            }

            // A name, because a file has one everywhere else in this service and
            // a record that says "signature.png" reads better than one that says
            // nothing.
            const drawn = new File([blob], 'signature.png', { type: 'image/png' });

            this.dispatch('pick', { prefix: 'file', detail: drawn });
            // Whatever the file controller put there is the upload; adopting it
            // is what makes this promise mean "and the bytes have landed".
            landed?.(widget?.filePending ?? null);
        }, 'image/png');
    }

    // Resizing a canvas clears it, so what is on it is kept and put back at the
    // new size. Anything else would throw away somebody's signature because they
    // turned their phone.
    #fit() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const drawn = this.pad.toData();

        this.padTarget.width = this.padTarget.offsetWidth * ratio;
        this.padTarget.height = this.padTarget.offsetHeight * ratio;
        this.padTarget.getContext('2d').scale(ratio, ratio);

        this.pad.clear();

        if (drawn.length > 0) this.pad.fromData(drawn);
    }
}
