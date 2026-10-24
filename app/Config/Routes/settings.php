<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('more', 'More::index');
$routes->get('settings/security', 'Settings\Security::index');
$routes->post('settings/security/devices/(:num)/revoke', 'Settings\Security::revokeDevice/$1');
