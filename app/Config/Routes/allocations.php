<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('tasks/(:num)/allocations/new', 'Allocations::new/$1');
$routes->post('tasks/(:num)/allocations', 'Allocations::create/$1');
$routes->get('allocations/(:num)/edit', 'Allocations::edit/$1');
$routes->post('allocations/(:num)', 'Allocations::update/$1');
$routes->get('allocations/(:num)/delete', 'Allocations::confirmDelete/$1');
$routes->post('allocations/(:num)/delete', 'Allocations::delete/$1');
