<?php

use App\Libraries\ErrorPresenter;

$error = ErrorPresenter::manual([
    'kind'     => 'generic',
    'title'    => 'Something went wrong',
    'headline' => 'We hit a snag',
    'message'  => 'An unexpected error stopped this page from loading. Please try again in a moment.',
    'hint'     => 'If this keeps happening, contact your administrator.',
    'icon'     => 'fa-triangle-exclamation',
    'code'     => (int) ($code ?? 500),
    'show_details' => false,
]);

include __DIR__ . DIRECTORY_SEPARATOR . 'app_error.php';
