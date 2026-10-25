<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// Statické cesty pred (:num).
$routes->get('settings/entities', 'Settings\Entities::index');
$routes->get('settings/entities/new', 'Settings\Entities::new');
$routes->get('settings/entities/regime-fields', 'Settings\Entities::regimeFields');
$routes->post('settings/entities', 'Settings\Entities::create');
$routes->get('settings/entities/(:num)', 'Settings\Entities::show/$1');
