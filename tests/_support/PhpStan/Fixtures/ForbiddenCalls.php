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

    /** WR-07: dalsie zdroje casu a floatu. */
    public function more(\DateTime $m): void
    {
        date_create_from_format('Y', '2026');
        date_create_immutable_from_format('Y', '2026');
        getdate();
        localtime();
        idate('Y');
        hrtime(true);
        gettimeofday();
        \DateTimeImmutable::createFromMutable($m);

        floor(1);
        ceil(1);
        fdiv(1, 2);
        fmod(1, 2);
        pow(2, 2);
        sqrt(4);
        $x = 1.5;
        $i = 1 / 2;
        $i /= 2;
        $p = 2 ** 2;
        $f = FILTER_VALIDATE_FLOAT;
        $v = '1';
        settype($v, 'float');
        sprintf('%.2f', 1);
        printf('%e', 1);
    }

    /** Povolene: nesmu vyprodukovat chybu. */
    public function allowed(): void
    {
        $v = '1';
        settype($v, 'int');
        sprintf('%d %s %%', 1, 'x');
        intdiv(1, 2);
        $n = 2 * 3;
    }
}
