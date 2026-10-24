<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('login', 'Auth\Login::show');
$routes->post('login', 'Auth\Login::attempt');
$routes->post('logout', 'Auth\Login::logout');
$routes->get('2fa', 'Auth\TwoFactor::index');
$routes->get('2fa/setup', 'Auth\TwoFactor::setup');
$routes->post('2fa/setup', 'Auth\TwoFactor::activate');
$routes->get('2fa/verify', 'Auth\TwoFactor::verify');
$routes->post('2fa/verify', 'Auth\TwoFactor::check');
