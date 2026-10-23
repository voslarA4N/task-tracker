<?php

declare(strict_types=1);

if (! function_exists('asset_url')) {
    /**
     * URL staticky suboru pod /assets/. Vendor subory maju verziu v nazve,
     * vlastne dostanu ?v= podla casu zmeny suboru (dlha cache + invalidacia).
     */
    function asset_url(string $path): string
    {
        $path = ltrim($path, '/');
        $url  = '/assets/' . $path;

        if (str_starts_with($path, 'vendor/')) {
            return $url;
        }

        $mtime = @filemtime(FCPATH . 'assets/' . $path);

        return $url . '?v=' . ($mtime === false ? 0 : $mtime);
    }
}
