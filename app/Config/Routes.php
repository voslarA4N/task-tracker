<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('demo', 'Demo::index');
$routes->post('demo/calculate', 'Demo::calculate');
