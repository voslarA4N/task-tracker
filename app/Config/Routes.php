<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('demo', 'Demo::index');
$routes->post('demo/calculate', 'Demo::calculate');
$routes->post('demo/calculations', 'Demo::store');

/*
 * Modulove routy jednotlivych rezov (auth, pwa, settings, password).
 */
$routeFiles = glob(APPPATH . 'Config/Routes/*.php');
sort($routeFiles);

foreach ($routeFiles as $routeFile) {
    require $routeFile;
}
