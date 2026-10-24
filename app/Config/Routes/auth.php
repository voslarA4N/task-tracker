<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('login', 'Auth\Login::show');
$routes->post('login', 'Auth\Login::attempt');
$routes->post('logout', 'Auth\Login::logout');
