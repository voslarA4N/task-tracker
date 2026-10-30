<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Fx\HttpResult;
use App\Libraries\Fx\RateHttpClient;
use App\Libraries\Fx\RateUnavailable;
use Throwable;

/**
 * HTTP klient bez siete: mapa URL na odpoved alebo vynimku, zaznam volani. Neznama URL = RateUnavailable.
 */
final class FakeRateHttpClient implements RateHttpClient
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param array<string, HttpResult|Throwable> $responses
     */
    public function __construct(private array $responses = [])
    {
    }

    public function respond(string $url, int $status, string $body): void
    {
        $this->responses[$url] = new HttpResult($status, $body);
    }

    public function failWith(string $url, Throwable $e): void
    {
        $this->responses[$url] = $e;
    }

    public function get(string $url): HttpResult
    {
        $this->calls[] = $url;
        $r             = $this->responses[$url] ?? null;

        if ($r instanceof Throwable) {
            throw $r;
        }

        return $r ?? throw new RateUnavailable('http_error');
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/fx/' . $name);
    }
}
