<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('reports/month.csv', 'Reports::monthCsv');
$routes->get('reports/year.csv', 'Reports::yearCsv');
$routes->get('reports/month', 'Reports::month');
$routes->get('reports/year', 'Reports::year');
