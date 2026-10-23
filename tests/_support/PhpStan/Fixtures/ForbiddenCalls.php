<?php

declare(strict_types=1);

namespace Tests\Support\PhpStan\Fixtures;

final class ForbiddenCalls
{
    public function all(): void
    {
        date('Y');
        time();
        strtotime('now');
        microtime(true);
        mktime(0);
        date_create();
        new \DateTime();
        new \DateTimeImmutable();
        \CodeIgniter\I18n\Time::now();
        \DateTimeImmutable::createFromFormat('Y', '2026');
        (float) '1.5';
        floatval('1.5');
        round(1.5, 2);
        number_format(1.5, 2);
        bcadd('1', '2', 2);
        bcscale(2);
    }
}
