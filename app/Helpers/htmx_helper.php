<?php

declare(strict_types=1);

if (! function_exists('is_htmx')) {
    /**
     * Hlavicka HX-Request = true (htmx 2 neposiela X-Requested-With).
     */
    function is_htmx(): bool
    {
        return service('request')->getHeaderLine('HX-Request') === 'true';
    }
}

if (! function_exists('is_htmx_fragment_request')) {
    /**
     * htmx poziadavka, ktora nie je boosted ani obnova historie (tie chcu celu stranku).
     */
    function is_htmx_fragment_request(): bool
    {
        $request = service('request');

        return is_htmx()
            && $request->getHeaderLine('HX-Boosted') !== 'true'
            && $request->getHeaderLine('HX-History-Restore-Request') !== 'true';
    }
}

if (! function_exists('htmx_view')) {
    /**
     * @param array<string, mixed> $data
     */
    function htmx_view(string $fragment, array $data, string $page): string
    {
        return is_htmx_fragment_request() ? view($fragment, $data) : view($page, $data);
    }
}
