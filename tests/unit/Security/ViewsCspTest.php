<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @internal
 */
#[Group('unit')]
final class ViewsCspTest extends CIUnitTestCase
{
    private const PATTERNS = [
        'inline <script>'   => '/<script\b(?![^>]*\bsrc=)[^>]*>/i',
        '<style>'           => '/<style\b/i',
        'atribut style='    => '/\sstyle\s*=/i',
        'atribut on*='      => '/\son[a-z]+\s*=/i',
        'javascript: URL'   => '/javascript:/i',
    ];

    public function testViewsHaveNoInlineCode(): void
    {
        $problems = [];
        $base     = APPPATH . 'Views';
        $it       = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php'
                || str_ends_with($path, 'errors/html/error_exception.php')
                || str_contains($path, 'errors/cli/')) {
                continue;
            }

            foreach (file($path) ?: [] as $i => $line) {
                foreach (self::PATTERNS as $name => $re) {
                    if (preg_match($re, $line) === 1) {
                        $problems[] = "{$path}:" . ($i + 1) . " {$name}";
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
