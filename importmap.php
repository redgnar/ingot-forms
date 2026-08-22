<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    // One entrypoint per skin: each imports its own Bootstrap and then the same
    // kit, so a page loads exactly one stylesheet for the look it was given and
    // the rest is shared. Which one a page asks for is the document's business
    // (or this deployment's default), never the browser's.
    'bootstrap-form-default' => [
        'path' => './assets/pages/bootstrap-form-default.js',
        'entrypoint' => true,
    ],
    'bootstrap-form-material' => [
        'path' => './assets/pages/bootstrap-form-material.js',
        'entrypoint' => true,
    ],
    'bootstrap-form-flatly' => [
        'path' => './assets/pages/bootstrap-form-flatly.js',
        'entrypoint' => true,
    ],
    'bootstrap-form-lux' => [
        'path' => './assets/pages/bootstrap-form-lux.js',
        'entrypoint' => true,
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    'tom-select' => [
        'version' => '2.6.2',
    ],
    '@orchidjs/sifter' => [
        'version' => '1.1.0',
    ],
    '@orchidjs/unicode-variants' => [
        'version' => '1.1.2',
    ],
    'bootstrap/dist/css/bootstrap.min.css' => [
        'version' => '5.3.8',
        'type' => 'css',
    ],
    'tom-select/dist/css/tom-select.bootstrap5.min.css' => [
        'version' => '2.6.2',
        'type' => 'css',
    ],
    'bootswatch/dist/materia/bootstrap.min.css' => [
        'version' => '5.3.8',
        'type' => 'css',
    ],
    'bootswatch/dist/flatly/bootstrap.min.css' => [
        'version' => '5.3.8',
        'type' => 'css',
    ],
    'bootswatch/dist/lux/bootstrap.min.css' => [
        'version' => '5.3.8',
        'type' => 'css',
    ],
];
