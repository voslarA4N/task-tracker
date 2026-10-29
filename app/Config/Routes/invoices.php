<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// invoices/new a dalsie staticke trasy (05-08) su v Routes/invoicing.php, (:num) slovo "new" nezachyti.
$routes->get('invoices', 'Invoices::index');
$routes->get('invoices/(:num)', 'Invoices::show/$1');
$routes->get('invoices/(:num)/pdf', 'Invoices::pdf/$1');
$routes->get('invoices/(:num)/report', 'Invoices::report/$1');
$routes->get('invoices/(:num)/send', 'InvoiceDelivery::sendForm/$1');
$routes->post('invoices/(:num)/send', 'InvoiceDelivery::send/$1');
$routes->get('invoices/(:num)/resend', 'InvoiceDelivery::resendForm/$1');
$routes->post('invoices/(:num)/resend', 'InvoiceDelivery::resend/$1');
$routes->get('invoices/(:num)/payments', 'Payments::form/$1');
$routes->post('invoices/(:num)/payments', 'Payments::record/$1');
$routes->post('invoices/(:num)/payments/impact', 'Payments::impact/$1');
$routes->get('payments/(:num)/void', 'Payments::voidForm/$1');
$routes->post('payments/(:num)/void', 'Payments::void/$1');
