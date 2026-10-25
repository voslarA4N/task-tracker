<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// Statické cesty pred (:num).
$routes->get('settings/entities', 'Settings\Entities::index');
$routes->get('settings/entities/new', 'Settings\Entities::new');
$routes->get('settings/entities/regime-fields', 'Settings\Entities::regimeFields');
$routes->post('settings/entities', 'Settings\Entities::create');
$routes->get('settings/entities/(:num)', 'Settings\Entities::show/$1');
$routes->get('settings/entities/(:num)/versions/new', 'Settings\EntityVersions::newForm/$1');
$routes->get('settings/entities/(:num)/versions/notice', 'Settings\EntityVersions::notice/$1');
$routes->post('settings/entities/(:num)/versions', 'Settings\EntityVersions::create/$1');
