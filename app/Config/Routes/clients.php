<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// Statické cesty pred (:num). Zámerne bez routy na zmazanie (D-15).
$routes->get('settings/clients', 'Settings\Clients::index');
$routes->get('settings/clients/new', 'Settings\Clients::new');
$routes->post('settings/clients', 'Settings\Clients::create');
$routes->get('settings/clients/(:num)/edit', 'Settings\Clients::edit/$1');
$routes->post('settings/clients/(:num)', 'Settings\Clients::update/$1');
