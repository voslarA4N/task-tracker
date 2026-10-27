<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('invoices/new', 'Invoicing::new');
$routes->get('fragments/invoice-preview', 'Invoicing::preview');
$routes->get('invoices/preview-move/(:num)', 'Invoicing::moveSheet/$1');
$routes->post('invoices/preview-move/(:num)', 'Invoicing::move/$1');
