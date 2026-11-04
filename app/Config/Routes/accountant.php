<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('reports/accountant', 'Accountant::index');
$routes->get('reports/accountant/preview', 'Accountant::preview');
$routes->get('reports/accountant/download', 'Accountant::download');
