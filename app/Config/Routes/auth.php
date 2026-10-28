<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('login', 'Auth\Login::show');
$routes->post('login', 'Auth\Login::attempt');
$routes->post('logout', 'Auth\Login::logout');
$routes->get('2fa', 'Auth\TwoFactor::index');
$routes->get('2fa/offer', 'Auth\TwoFactor::offer');
$routes->post('2fa/offer/decline', 'Auth\TwoFactor::decline');
$routes->get('2fa/continue', 'Auth\TwoFactor::continuePage');
$routes->post('2fa/continue', 'Auth\TwoFactor::proceed');
$routes->get('2fa/setup', 'Auth\TwoFactor::setup');
$routes->post('2fa/setup', 'Auth\TwoFactor::activate');
$routes->get('2fa/verify', 'Auth\TwoFactor::verify');
$routes->post('2fa/verify', 'Auth\TwoFactor::check');
$routes->get('2fa/backup-codes', 'Auth\TwoFactor::backupCodes');
$routes->get('2fa/backup-codes.txt', 'Auth\TwoFactor::backupCodesTxt');
$routes->post('2fa/backup-codes', 'Auth\TwoFactor::confirmBackupCodes');
$routes->get('2fa/backup', 'Auth\TwoFactor::backup');
$routes->post('2fa/backup', 'Auth\TwoFactor::checkBackup');
