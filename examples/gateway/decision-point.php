<?php

declare(strict_types=1);

/**
 * A stand-in for the thing this service deliberately does not contain.
 *
 * "May this caller touch this form" is answered by whoever created the form,
 * because they already know. This file is not that answer — it is the *shape*
 * of it, small enough to read in a minute and to smoke-test the gateway with:
 *
 *   in   X-Forms-Decision-Form     which form
 *        X-Forms-Decision-Subject  who is asking, as the gateway authenticated them
 *        X-Forms-Decision-Method   GET | PUT | POST | DELETE
 *        X-Forms-Decision-Path     the address they are asking for
 *   out  204  yes
 *        403  no
 *
 * Anything but 2xx is a refusal, including a timeout or this process being
 * down: nginx's `auth_request` fails closed, which is the right way round.
 *
 * The rule below is a demonstration and nothing more: a real one looks the
 * subject up against whatever owns the form, and its answer is a fact about
 * that system rather than about this file.
 */
$said = static function (string $header): string {
    $value = $_SERVER[$header] ?? '';

    return \is_string($value) ? $value : '';
};

$form = $said('HTTP_X_FORMS_DECISION_FORM');
$subject = $said('HTTP_X_FORMS_DECISION_SUBJECT');
$method = $said('HTTP_X_FORMS_DECISION_METHOD');

error_log(\sprintf(
    'decide form=%s subject=%s method=%s',
    $form,
    $subject === '' ? '-' : $subject,
    $method === '' ? 'GET' : $method,
));

// Nobody is anybody: the gateway authenticated no one, so there is nothing to
// decide about. Refusing here rather than passing it on is what keeps an
// unauthenticated request from reaching a form at all.
if ($subject === '') {
    http_response_code(403);

    return;
}

// The demonstration rule: `demo-*` may read and fill; `owner-*` may also
// delete. A real one asks the system that created the form.
$allowed = match (true) {
    str_starts_with($subject, 'owner-') => true,
    str_starts_with($subject, 'demo-') => $method !== 'DELETE',
    default => false,
};

http_response_code($allowed ? 204 : 403);
