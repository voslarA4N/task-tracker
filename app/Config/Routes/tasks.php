<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// Statické cesty pred (:num).
$routes->get('tasks', 'Tasks::index');
$routes->get('tasks/new', 'Tasks::new');
$routes->post('tasks', 'Tasks::create');
$routes->get('tasks/client-defaults', 'Tasks::clientDefaults');
$routes->get('tasks/portal-preview', 'Tasks::portalPreview');
$routes->get('tasks/(:num)', 'Tasks::show/$1');
$routes->get('tasks/(:num)/edit', 'Tasks::edit/$1');
$routes->post('tasks/(:num)', 'Tasks::update/$1');
$routes->post('tasks/(:num)/delete', 'Tasks::delete/$1');
