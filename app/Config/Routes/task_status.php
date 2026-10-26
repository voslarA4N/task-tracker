<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('tasks/(:num)/done', 'TaskStatus::donePanel/$1');
$routes->post('tasks/(:num)/done', 'TaskStatus::markDone/$1');
$routes->post('tasks/(:num)/reopen', 'TaskStatus::reopen/$1');
