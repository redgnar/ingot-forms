// Everything the `bootstrap` kit's page loads: the kit's stylesheet, the widget
// library one of its controls is built on, and the Stimulus application that
// registers whatever sits in `assets/controllers/`.
//
// The page is still a client of this service's own API and nothing more — the
// controllers below talk to `/api/forms/{id}`, exactly as any other client
// would. Behaviour got a framework here; the write path did not change.
import 'bootstrap/dist/css/bootstrap.min.css';
import '../styles/bootstrap-form.css';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';
import { startStimulusApp } from '@symfony/stimulus-bundle';

startStimulusApp();
