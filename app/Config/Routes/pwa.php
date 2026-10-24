<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('sw.js', 'Pwa::serviceWorker');
$routes->get('offline', 'Pwa::offline');
