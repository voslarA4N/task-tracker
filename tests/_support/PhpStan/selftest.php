<?php

declare(strict_types=1);

/** Self-test pravidla ForbiddenCallsRule: fixture musí vyprodukovať presný počet chýb. */
$root     = dirname(__DIR__, 3);
$expected = ['app.forbiddenTime' => 10, 'app.forbiddenFloat' => 4, 'app.forbiddenBcmath' => 2];

$cmd  = [PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '-c', 'phpstan.selftest.neon', '--error-format=json', '--no-progress'];
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (! is_resource($proc)) {
    fwrite(STDERR, "Nepodarilo sa spustit phpstan\n");

    exit(1);
}
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
proc_close($proc);

$json = json_decode((string) $out, true);
if (! is_array($json)) {
    fwrite(STDERR, "Neplatny vystup phpstan:\n{$out}\n{$err}\n");

    exit(1);
}

$actual = array_fill_keys(array_keys($expected), 0);

foreach ($json['files'] ?? [] as $file) {
    foreach ($file['messages'] as $message) {
        $id          = $message['identifier'] ?? 'other';
        $actual[$id] = ($actual[$id] ?? 0) + 1;
    }
}

$parts = [];

foreach ($actual as $id => $count) {
    $parts[] = $id . '=' . $count;
}
echo implode(' ', $parts), "\n";

if ($actual !== $expected) {
    fwrite(STDERR, 'Ocakavane: ' . json_encode($expected) . "\nSkutocne: " . json_encode($actual) . "\n");

    exit(1);
}

exit(0);
