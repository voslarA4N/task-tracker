<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// Statické cesty pred (:num).
$routes->get('settings/entities', 'Settings\Entities::index');
$routes->get('settings/entities/new', 'Settings\Entities::new');
$routes->get('settings/entities/regime-fields', 'Settings\Entities::regimeFields');
$routes->post('settings/entities', 'Settings\Entities::create');
$routes->get('settings/entities/(:num)', 'Settings\Entities::show/$1');
$routes->get('settings/entities/(:num)/versions/new', 'Settings\EntityVersions::newForm/$1');
$routes->get('settings/entities/(:num)/versions/notice', 'Settings\EntityVersions::notice/$1');
$routes->post('settings/entities/(:num)/versions', 'Settings\EntityVersions::create/$1');
$routes->get('settings/entities/(:num)/edit', 'Settings\Entities::edit/$1');
$routes->post('settings/entities/(:num)', 'Settings\Entities::update/$1');
$routes->post('settings/entities/(:num)/end', 'Settings\Entities::end/$1');
$routes->get('settings/entities/(:num)/bank-accounts/new', 'Settings\BankAccounts::newForm/$1');
$routes->get('settings/entities/(:num)/bank-accounts/archived', 'Settings\BankAccounts::archived/$1');
$routes->post('settings/entities/(:num)/bank-accounts', 'Settings\BankAccounts::create/$1');
$routes->get('settings/entities/(:num)/bank-accounts/(:num)/edit', 'Settings\BankAccounts::edit/$1/$2');
$routes->post('settings/entities/(:num)/bank-accounts/(:num)', 'Settings\BankAccounts::update/$1/$2');
$routes->post('settings/entities/(:num)/bank-accounts/(:num)/default', 'Settings\BankAccounts::setDefault/$1/$2');
$routes->post('settings/entities/(:num)/bank-accounts/(:num)/archive', 'Settings\BankAccounts::archive/$1/$2');
