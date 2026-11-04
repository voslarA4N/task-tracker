<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('more', 'More::index');
$routes->get('settings/security', 'Settings\Security::index');
$routes->post('settings/security/devices/(:num)/revoke', 'Settings\Security::revokeDevice/$1');
$routes->post('settings/security/password', 'Settings\Security::changePassword');
$routes->get('settings/security/2fa/setup', 'Settings\Security::setupTotp');
$routes->post('settings/security/2fa/setup', 'Settings\Security::enableTotp');
$routes->post('settings/security/2fa/disable', 'Settings\Security::disableTotp');
$routes->post('settings/security/backup-codes', 'Settings\Security::regenerateCodes');
$routes->get('settings/security/backup-codes', 'Settings\Security::showCodes');
$routes->get('settings/security/backup-codes.txt', 'Settings\Security::codesTxt');
$routes->post('settings/security/backup-codes/done', 'Settings\Security::confirmCodes');
$routes->get('settings/legal-texts', 'Settings\LegalTexts::index');
$routes->get('settings/legal-texts/new', 'Settings\LegalTexts::new');
$routes->post('settings/legal-texts', 'Settings\LegalTexts::create');
$routes->get('settings/legal-texts/(:num)/edit', 'Settings\LegalTexts::edit/$1');
$routes->post('settings/legal-texts/(:num)', 'Settings\LegalTexts::update/$1');
$routes->get('settings/email-templates', 'Settings\EmailTemplates::index');
$routes->get('settings/email-templates/(:num)/edit', 'Settings\EmailTemplates::edit/$1');
$routes->post('settings/email-templates/(:num)/preview', 'Settings\EmailTemplates::preview/$1');
$routes->post('settings/email-templates/(:num)', 'Settings\EmailTemplates::update/$1');
$routes->get('settings/fx', 'Settings\FxSettings::index');
$routes->post('settings/fx', 'Settings\FxSettings::update');
$routes->get('settings/documents', 'Settings\DocumentSettings::index');
$routes->post('settings/documents', 'Settings\DocumentSettings::update');
$routes->get('settings/limits', 'Settings\Limits::index');
$routes->get('settings/limits/new', 'Settings\Limits::newForm');
$routes->post('settings/limits', 'Settings\Limits::create');
$routes->get('settings/limits/opening', 'Settings\Limits::openingForm');
$routes->post('settings/limits/opening', 'Settings\Limits::saveOpening');
$routes->get('settings/limits/(:num)/edit', 'Settings\Limits::edit/$1');
$routes->post('settings/limits/(:num)', 'Settings\Limits::update/$1');
$routes->get('settings/limits/(:num)/delete', 'Settings\Limits::deleteForm/$1');
$routes->post('settings/limits/(:num)/delete', 'Settings\Limits::delete/$1');
$routes->get('settings/audit', 'Settings\Audit::index');
$routes->get('settings/audit/(:num)', 'Settings\Audit::show/$1');
$routes->get('settings/users', 'Settings\Users::index');
