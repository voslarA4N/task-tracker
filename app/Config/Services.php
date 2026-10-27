<?php

namespace Config;

use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Platform\AfterResponse;
use App\Libraries\Platform\Clock;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function clock(bool $getShared = true): Clock
    {
        if ($getShared) {
            return static::getSharedInstance('clock');
        }

        return new Clock();
    }

    public static function invoiceIssuer(bool $getShared = true): InvoiceIssuer
    {
        if ($getShared) {
            return static::getSharedInstance('invoiceIssuer');
        }

        return new InvoiceIssuer();
    }

    public static function afterResponse(bool $getShared = true): AfterResponse
    {
        if ($getShared) {
            return static::getSharedInstance('afterResponse');
        }

        return new AfterResponse();
    }

    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
}
