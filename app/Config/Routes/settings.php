<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('more', 'More::index');
$routes->get('settings/security', 'Settings\Security::index');
$routes->post('settings/security/devices/(:num)/revoke', 'Settings\Security::revokeDevice/$1');
$routes->post('settings/security/password', 'Settings\Security::changePassword');
$routes->post('settings/security/backup-codes', 'Settings\Security::regenerateCodes');
$routes->get('settings/security/backup-codes', 'Settings\Security::showCodes');
$routes->get('settings/security/backup-codes.txt', 'Settings\Security::codesTxt');
$routes->post('settings/security/backup-codes/done', 'Settings\Security::confirmCodes');
