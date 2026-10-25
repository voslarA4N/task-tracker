<?php

declare(strict_types=1);

namespace App\Libraries\Platform;

use Closure;
use Throwable;

/**
 * Praca po odoslani odpovede (service('afterResponse')). Pod PHP-FPM sa ulohy spustia
 * az po fastcgi_finish_request(), takze cas odpovede od nich nezavisi (WR-03: SMTP pri
 * obnove hesla nesmie prezradit existenciu uctu). Mimo FPM (CLI, testy) bezia hned.
 */
final class AfterResponse
{
    /**
     * @var list<Closure(): void>
     */
    private array $tasks = [];

    private bool $registered = false;

    /**
     * @param bool|null $defer null = podla SAPI; false = vzdy hned; true = drzat do flush() (testy)
     */
    public function __construct(private readonly ?bool $defer = null)
    {
    }

    /**
     * @param Closure(): void $task
     */
    public function defer(Closure $task): void
    {
        if (! $this->shouldDefer()) {
            $task();

            return;
        }

        $this->tasks[] = $task;

        if ($this->defer === null && ! $this->registered) {
            register_shutdown_function($this->finishAndFlush(...));
            $this->registered = true;
        }
    }

    /**
     * Pocet uloh, ktore cakaju na flush().
     */
    public function pending(): int
    {
        return count($this->tasks);
    }

    /**
     * Spusti cakajuce ulohy. Chyba jednej ulohy nezastavi ostatne a do logu ide iba jej trieda.
     */
    public function flush(): void
    {
        $tasks       = $this->tasks;
        $this->tasks = [];

        foreach ($tasks as $task) {
            try {
                $task();
            } catch (Throwable $e) {
                log_message('error', 'after_response.failed ' . $e::class);
            }
        }
    }

    /**
     * Shutdown funkcia: zapise session (dalsia poziadavka nema cakat na zamok), ukonci odpoved
     * klientovi a az potom vykona ulohy.
     */
    public function finishAndFlush(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $this->flush();
    }

    private function shouldDefer(): bool
    {
        return $this->defer ?? (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request'));
    }
}
