<?php

declare(strict_types=1);

namespace App\Controllers;

final class More extends BaseController
{
    public function index(): string
    {
        return view('more/index', ['title' => lang('Account.moreTitle')]);
    }
}
