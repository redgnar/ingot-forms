// Everything the `bootstrap` kit's page loads whatever it is wearing: the widget
// library one of its controls is built on, the handful of rules that are this
// kit's own, what a reader can ask of any page, and the Stimulus application
// that registers whatever sits in `assets/controllers/`.
//
// Which Bootstrap came first is the skin's business — an entrypoint per skin
// imports one stylesheet and then this file, so exactly one is ever loaded and
// everything here still wins over it.
//
// The page is a client of this service's own API and nothing more: the
// controllers talk to `/api/forms/{id}`, exactly as any other client would.
// Behaviour got a framework here; the write path did not change.
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';
import '../styles/bootstrap-form.css';
import '../styles/comfort.css';
import { startStimulusApp } from '@symfony/stimulus-bundle';

startStimulusApp();
