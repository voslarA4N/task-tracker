<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('password/forgot', 'Auth\PasswordReset::forgot');
$routes->post('password/forgot', 'Auth\PasswordReset::send');
$routes->get('password/reset/(:segment)', 'Auth\PasswordReset::edit/$1');
$routes->post('password/reset/(:segment)', 'Auth\PasswordReset::update/$1');
