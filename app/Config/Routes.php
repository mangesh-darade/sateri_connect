<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Home::index');

/*
 * --------------------------------------------------------------------
 * Installer (public)
 * --------------------------------------------------------------------
 */
$routes->group('install', static function ($routes) {
    $routes->get('/', 'Install::index');
    $routes->get('requirements', 'Install::requirements');
    $routes->match(['get', 'post'], 'database', 'Install::database');
    $routes->match(['get', 'post'], 'migrate', 'Install::migrate');
    $routes->match(['get', 'post'], 'admin', 'Install::admin');
    $routes->match(['get', 'post'], 'cheerio', 'Install::cheerio');
    $routes->match(['get', 'post'], 'meta', 'Install::cheerio'); // legacy alias
    $routes->match(['get', 'post'], 'finish', 'Install::finish');
});

/*
 * --------------------------------------------------------------------
 * Auth (public, CSRF on POST)
 * --------------------------------------------------------------------
 */
$routes->match(['get', 'post'], 'login', 'Auth::login', ['filter' => 'csrf']);
$routes->match(['get', 'post'], 'signup', 'Auth::signup', ['filter' => 'csrf']);
$routes->get('verify-email/(:segment)', 'Auth::verifyEmail/$1');
$routes->match(['get', 'post'], 'resend-verification', 'Auth::resendVerification', ['filter' => 'csrf']);
$routes->get('logout', 'Auth::logout');
$routes->match(['get', 'post'], 'forgot-password', 'Auth::forgotPassword', ['filter' => 'csrf']);
$routes->match(['get', 'post'], 'reset-password/(:segment)', 'Auth::resetPassword/$1', ['filter' => 'csrf']);

/*
 * --------------------------------------------------------------------
 * WhatsApp Webhooks (public — no auth, no CSRF; WhatsApp-shaped payload via Cheerio)
 * --------------------------------------------------------------------
 */
$routes->match(['get', 'post'], 'webhooks', 'Webhooks::index');
$routes->match(['get', 'post'], 'webhook', 'Webhooks::index');

/*
 * --------------------------------------------------------------------
 * Authenticated panel
 * --------------------------------------------------------------------
 */
$routes->group('', ['filter' => 'auth'], static function ($routes) {
    $routes->get('dashboard', 'Dashboard::index');
    $routes->get('guide', 'Guide::index');
    $routes->get('guide/(:segment)', 'Guide::show/$1');

    // Settings
    $routes->get('settings', 'Settings::index');
    $routes->post('settings/save', 'Settings::save', ['filter' => 'csrf']);
    $routes->post('settings/test-smtp', 'Settings::testSmtp', ['filter' => 'csrf']);
    $routes->post('settings/test-email', 'Settings::testEmail', ['filter' => 'csrf']);
    $routes->post('settings/test-cheerio', 'Settings::testCheerio', ['filter' => 'csrf']);
    $routes->post('settings/test-meta', 'Settings::testMeta', ['filter' => 'csrf']);
    $routes->post('settings/embedded-signup', 'Settings::embeddedSignup', ['filter' => 'csrf']);
    $routes->post('settings/test-page-messaging', 'Settings::testPageMessaging', ['filter' => 'csrf']);
    $routes->post('settings/test-whatsapp', 'Settings::testCheerio', ['filter' => 'csrf']); // active provider via UI uses specific buttons
    $routes->post('settings/setup-webhook', 'Settings::setupWebhook', ['filter' => 'csrf']);

    // Contacts
    $routes->get('contacts', 'Contacts::index');
    $routes->get('contacts/create', 'Contacts::create');
    $routes->post('contacts', 'Contacts::store', ['filter' => 'csrf']);
    $routes->get('contacts/search', 'Contacts::search');
    $routes->get('contacts/duplicates', 'Contacts::detectDuplicates');
    $routes->match(['get', 'post'], 'contacts/import', 'Contacts::importCsv', ['filter' => 'csrf']);
    $routes->post('contacts/import/preview', 'Contacts::importPreview', ['filter' => 'csrf']);
    $routes->post('contacts/import/commit', 'Contacts::importCommit', ['filter' => 'csrf']);
    $routes->get('contacts/export', 'Contacts::exportCsv');
    $routes->post('contacts/bulk-delete', 'Contacts::bulkDelete', ['filter' => 'csrf']);
    $routes->post('contacts/bulk-tags', 'Contacts::bulkTags', ['filter' => 'csrf']);
    $routes->post('contacts/sync-cheerio', 'Contacts::syncFromCheerio', ['filter' => 'csrf']);
    $routes->get('contacts/(:num)', 'Contacts::show/$1');
    $routes->get('contacts/(:num)/edit', 'Contacts::edit/$1');
    $routes->post('contacts/(:num)', 'Contacts::update/$1', ['filter' => 'csrf']);
    $routes->post('contacts/(:num)/delete', 'Contacts::delete/$1', ['filter' => 'csrf']);

    // Customer Groups (campaign audience lists — backed by tags)
    $routes->get('customer-groups', 'CustomerGroups::index');
    $routes->post('customer-groups', 'CustomerGroups::store', ['filter' => 'csrf']);
    $routes->post('customer-groups/create', 'CustomerGroups::createGroup', ['filter' => 'csrf']);
    $routes->get('customer-groups/export', 'CustomerGroups::export');
    $routes->get('customer-groups/(:num)', 'CustomerGroups::show/$1');
    $routes->get('customer-groups/(:num)/export', 'CustomerGroups::export/$1');
    $routes->post('customer-groups/(:num)/delete', 'CustomerGroups::delete/$1', ['filter' => 'csrf']);
    $routes->post('customer-groups/(:num)/contacts/(:num)/remove', 'CustomerGroups::removeContact/$1/$2', ['filter' => 'csrf']);

    // Email Manager (builder, drips, verifier, HTML campaigns, sender/domain)
    $routes->get('email-manager', 'EmailManager::index');
    $routes->post('email-manager/builders', 'EmailManager::saveBuilder', ['filter' => 'csrf']);
    $routes->post('email-manager/builders/(:num)/delete', 'EmailManager::deleteBuilder/$1', ['filter' => 'csrf']);
    $routes->post('email-manager/drips', 'EmailManager::saveDrip', ['filter' => 'csrf']);
    $routes->post('email-manager/drips/(:num)/delete', 'EmailManager::deleteDrip/$1', ['filter' => 'csrf']);
    $routes->post('email-manager/drips/send-step', 'EmailManager::sendDripStep', ['filter' => 'csrf']);
    $routes->post('email-manager/verify', 'EmailManager::verifyEmails', ['filter' => 'csrf']);
    $routes->post('email-manager/campaigns', 'EmailManager::saveCampaign', ['filter' => 'csrf']);
    $routes->post('email-manager/campaigns/(:num)/send', 'EmailManager::sendCampaign/$1', ['filter' => 'csrf']);
    $routes->post('email-manager/campaigns/(:num)/delete', 'EmailManager::deleteCampaign/$1', ['filter' => 'csrf']);
    $routes->post('email-manager/senders', 'EmailManager::saveSender', ['filter' => 'csrf']);
    $routes->post('email-manager/senders/(:num)/delete', 'EmailManager::deleteSender/$1', ['filter' => 'csrf']);

    // Emails (single + bulk via active email provider)
    $routes->get('emails', 'Emails::index');
    $routes->get('emails/send', 'Emails::single');
    $routes->post('emails/send', 'Emails::sendSingle', ['filter' => 'csrf']);
    $routes->get('emails/bulk', 'Emails::bulk');
    $routes->post('emails/bulk', 'Emails::sendBulk', ['filter' => 'csrf']);

    // Global Analytics (WhatsApp + Email)
    $routes->get('analytics', 'Analytics::index');
    $routes->get('analytics/data', 'Analytics::data');

    // Campaigns
    $routes->get('campaigns', 'Campaigns::index');
    $routes->get('campaigns/create', 'Campaigns::create');
    $routes->get('campaigns/wizard-data', 'Campaigns::wizardData');
    $routes->post('campaigns/audience-preview', 'Campaigns::audiencePreview', ['filter' => 'csrf']);
    $routes->post('campaigns/labels', 'Campaigns::createLabel', ['filter' => 'csrf']);
    $routes->post('campaigns/wizard', 'Campaigns::wizardStore', ['filter' => 'csrf']);
    $routes->post('campaigns/wizard/(:segment)/(:num)/run', 'Campaigns::wizardRun/$1/$2', ['filter' => 'csrf']);
    $routes->post('campaigns/wizard/(:segment)/(:num)/schedule', 'Campaigns::wizardSchedule/$1/$2', ['filter' => 'csrf']);
    $routes->post('campaigns', 'Campaigns::store', ['filter' => 'csrf']);
    $routes->get('campaigns/(:num)', 'Campaigns::show/$1');
    $routes->get('campaigns/(:num)/edit', 'Campaigns::edit/$1');
    $routes->post('campaigns/(:num)', 'Campaigns::update/$1', ['filter' => 'csrf']);
    $routes->post('campaigns/(:num)/preview', 'Campaigns::preview/$1', ['filter' => 'csrf']);
    $routes->post('campaigns/(:num)/schedule', 'Campaigns::schedule/$1', ['filter' => 'csrf']);
    $routes->post('campaigns/(:num)/send-now', 'Campaigns::sendNow/$1', ['filter' => 'csrf']);
    $routes->post('campaigns/(:num)/pause', 'Campaigns::pause/$1', ['filter' => 'csrf']);
    $routes->post('campaigns/(:num)/resume', 'Campaigns::resume/$1', ['filter' => 'csrf']);
    $routes->post('campaigns/(:num)/cancel', 'Campaigns::cancel/$1', ['filter' => 'csrf']);
    $routes->post('campaigns/(:num)/delete', 'Campaigns::delete/$1', ['filter' => 'csrf']);
    $routes->get('campaigns/(:num)/analytics', 'Campaigns::analytics/$1');
    $routes->get('campaigns/(:num)/queue-status', 'Campaigns::queueStatus/$1');

    // Templates
    $routes->get('templates', 'Templates::index');
    $routes->get('templates/create', 'Templates::create');
    $routes->post('templates', 'Templates::store', ['filter' => 'csrf']);
    $routes->post('templates/header-media', 'Templates::uploadHeaderMedia', ['filter' => 'csrf']);
    $routes->post('templates/sync', 'Templates::sync', ['filter' => 'csrf']);
    $routes->get('templates/(:num)', 'Templates::show/$1');
    $routes->get('templates/(:num)/preview', 'Templates::preview/$1');
    $routes->post('templates/(:num)/delete', 'Templates::delete/$1', ['filter' => 'csrf']);

    // Chat
    $routes->get('chat', 'Chat::index');
    $routes->get('chat/conversations', 'Chat::conversations');
    $routes->get('chat/messages/(:num)', 'Chat::messages/$1');
    $routes->post('chat/send', 'Chat::send', ['filter' => 'csrf']);
    $routes->post('chat/mark-read', 'Chat::markRead', ['filter' => 'csrf']);
    $routes->post('chat/note', 'Chat::addNote', ['filter' => 'csrf']);
    $routes->post('chat/assign', 'Chat::assign', ['filter' => 'csrf']);
    $routes->post('chat/status', 'Chat::setStatus', ['filter' => 'csrf']);
    $routes->get('chat/search', 'Chat::search');

    // Live notifications (header bell + browser alerts)
    $routes->get('notifications/poll', 'Notifications::poll');
    $routes->post('notifications/(:num)/read', 'Notifications::markRead/$1', ['filter' => 'csrf']);
    $routes->post('notifications/read-all', 'Notifications::markAllRead', ['filter' => 'csrf']);

    // Automations
    $routes->get('automations', 'Automations::index');
    $routes->get('automations/create', 'Automations::create');
    $routes->post('automations', 'Automations::store', ['filter' => 'csrf']);
    $routes->get('automations/(:num)/edit', 'Automations::edit/$1');
    $routes->post('automations/(:num)', 'Automations::update/$1', ['filter' => 'csrf']);
    $routes->post('automations/(:num)/delete', 'Automations::delete/$1', ['filter' => 'csrf']);
    $routes->post('automations/(:num)/toggle', 'Automations::toggle/$1', ['filter' => 'csrf']);
    $routes->post('automations/sync-cheerio', 'Automations::syncFromCheerio', ['filter' => 'csrf']);
    $routes->get('automations/(:num)/builder', 'Automations::builderPage/$1');
    $routes->post('automations/(:num)/builder', 'Automations::builder/$1', ['filter' => 'csrf']);

    // Sequences (multi-step drips)
    $routes->get('sequences', 'Sequences::index');
    $routes->get('sequences/create', 'Sequences::create');
    $routes->post('sequences', 'Sequences::store', ['filter' => 'csrf']);
    $routes->get('sequences/(:num)/edit', 'Sequences::edit/$1');
    $routes->post('sequences/(:num)', 'Sequences::update/$1', ['filter' => 'csrf']);
    $routes->post('sequences/(:num)/enroll', 'Sequences::enroll/$1', ['filter' => 'csrf']);
    $routes->post('sequences/(:num)/delete', 'Sequences::delete/$1', ['filter' => 'csrf']);

    // Keywords
    $routes->get('keywords', 'Keywords::index');
    $routes->get('keywords/create', 'Keywords::create');
    $routes->post('keywords', 'Keywords::store', ['filter' => 'csrf']);
    $routes->get('keywords/(:num)/edit', 'Keywords::edit/$1');
    $routes->post('keywords/(:num)', 'Keywords::update/$1', ['filter' => 'csrf']);
    $routes->post('keywords/(:num)/delete', 'Keywords::delete/$1', ['filter' => 'csrf']);
    $routes->post('keywords/reorder', 'Keywords::reorder', ['filter' => 'csrf']);

    // Reports
    $routes->get('reports', 'Reports::index');
    $routes->get('reports/campaigns', 'Reports::campaigns');
    $routes->get('reports/delivery', 'Reports::delivery');
    $routes->get('reports/export-pdf', 'Reports::exportPdf');
    $routes->get('reports/export-excel', 'Reports::exportExcel');

    // Queue
    $routes->get('queue', 'Queue::index');
    $routes->get('queue/stats', 'Queue::stats');
    $routes->post('queue/(:num)/retry', 'Queue::retry/$1', ['filter' => 'csrf']);
    $routes->post('queue/(:num)/cancel', 'Queue::cancel/$1', ['filter' => 'csrf']);

    // Users
    $routes->get('users', 'Users::index');
    $routes->get('users/create', 'Users::create');
    $routes->post('users', 'Users::store', ['filter' => 'csrf']);
    $routes->get('users/(:num)/edit', 'Users::edit/$1');
    $routes->post('users/(:num)', 'Users::update/$1', ['filter' => 'csrf']);
    $routes->post('users/(:num)/delete', 'Users::delete/$1', ['filter' => 'csrf']);

    // Roles
    $routes->get('roles', 'Roles::index');
    $routes->post('roles', 'Roles::store', ['filter' => 'csrf']);
    $routes->post('roles/update', 'Roles::update', ['filter' => 'csrf']);
    $routes->post('roles/(:num)/delete', 'Roles::delete/$1', ['filter' => 'csrf']);

    // Media
    $routes->post('media/upload', 'Media::upload', ['filter' => 'csrf']);
    $routes->get('media/serve/(:segment)', 'Media::serve/$1');
});

/*
 * --------------------------------------------------------------------
 * REST API
 * --------------------------------------------------------------------
 */
$routes->group('api', ['namespace' => 'App\Controllers\Api'], static function ($routes) {
    $routes->post('auth/login', 'Auth::login', ['filter' => 'rateLimit:10,60']);

    // Optional API webhook (no JWT — signature validated inside)
    $routes->post('webhooks', 'Webhooks::receive');

    $routes->group('', ['filter' => 'apiAuth'], static function ($routes) {
        $routes->get('auth/me', 'Auth::me');

        $routes->get('contacts', 'Contacts::index', ['filter' => 'permission:contacts.view']);
        $routes->post('contacts', 'Contacts::create', ['filter' => 'permission:contacts.create']);
        $routes->get('contacts/(:num)', 'Contacts::show/$1', ['filter' => 'permission:contacts.view']);
        $routes->put('contacts/(:num)', 'Contacts::update/$1', ['filter' => 'permission:contacts.edit']);
        $routes->delete('contacts/(:num)', 'Contacts::delete/$1', ['filter' => 'permission:contacts.delete']);

        $routes->get('customer-groups', 'CustomerGroups::index', ['filter' => 'permission:contacts.view']);
        $routes->post('customer-groups', 'CustomerGroups::create', ['filter' => 'permission:contacts.create']);
        $routes->get('customer-groups/(:num)', 'CustomerGroups::show/$1', ['filter' => 'permission:contacts.view']);
        $routes->put('customer-groups/(:num)', 'CustomerGroups::update/$1', ['filter' => 'permission:contacts.edit']);
        $routes->delete('customer-groups/(:num)', 'CustomerGroups::delete/$1', ['filter' => 'permission:contacts.delete']);
        $routes->post('customer-groups/(:num)/contacts', 'CustomerGroups::addContact/$1', ['filter' => 'permission:contacts.create']);
        $routes->delete('customer-groups/(:num)/contacts/(:num)', 'CustomerGroups::removeContact/$1/$2', ['filter' => 'permission:contacts.edit']);

        $routes->get('campaigns', 'Campaigns::index', ['filter' => 'permission:campaigns.view']);
        $routes->post('campaigns', 'Campaigns::create', ['filter' => 'permission:campaigns.create']);
        $routes->get('campaigns/(:num)', 'Campaigns::show/$1', ['filter' => 'permission:campaigns.view']);
        $routes->post('campaigns/(:num)/pause', 'Campaigns::pause/$1', ['filter' => 'permission:campaigns.edit']);
        $routes->post('campaigns/(:num)/resume', 'Campaigns::resume/$1', ['filter' => 'permission:campaigns.edit']);

        $routes->get('messages', 'Messages::index', ['filter' => 'permission:chat.view']);
        $routes->post('messages/send', 'Messages::send', ['filter' => 'permission:chat.send']);
        $routes->post('messages/text', 'Messages::sendText', ['filter' => 'permission:chat.send']);
        $routes->post('messages/template', 'Messages::sendTemplate', ['filter' => 'permission:chat.send']);

        $routes->get('templates', 'Templates::index', ['filter' => 'permission:templates.view']);
        $routes->post('templates/sync', 'Templates::sync', ['filter' => 'permission:templates.sync']);

        $routes->get('reports/stats', 'Reports::stats', ['filter' => 'permission:reports.view']);
    });
});
