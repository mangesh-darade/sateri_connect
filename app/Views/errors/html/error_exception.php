<?php

/**
 * Fallback if the default CI handler is used — still show branded screen.
 * Prefer App\Libraries\AppExceptionHandler which always renders app_error.php.
 */

use App\Libraries\ErrorPresenter;

$error = ErrorPresenter::manual([
    'kind'             => 'generic',
    'title'            => isset($title) ? (string) $title : 'Something went wrong',
    'headline'         => 'We hit a snag',
    'message'          => 'An unexpected error stopped this page from loading. Please try again in a moment.',
    'hint'             => 'If this keeps happening, share the technical details below with your administrator.',
    'icon'             => 'fa-triangle-exclamation',
    'code'             => (int) ($code ?? 500),
    'technical'        => isset($message) ? (string) $message : '',
    'exception_class'  => isset($type) ? (string) $type : (isset($title) ? (string) $title : ''),
    'file'             => (string) ($file ?? ''),
    'line'             => (int) ($line ?? 0),
    'show_details'     => ! \defined('ENVIRONMENT') || \ENVIRONMENT !== 'production',
]);

include __DIR__ . DIRECTORY_SEPARATOR . 'app_error.php';
