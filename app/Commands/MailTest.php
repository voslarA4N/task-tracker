<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Pošle testovací e-mail cez SMTP z .env (vo vývoji Mailpit).
 *
 * @internal
 */
final class MailTest extends BaseCommand
{
    protected $group       = 'Dev';
    protected $name        = 'mail:test';
    protected $description = 'Odošle testovací e-mail na dev@work-tracker.loc.';
    protected $usage       = 'mail:test [subject]';
    protected $arguments   = ['subject' => 'Predmet správy (predvolene mail:test)'];

    public function run(array $params): int
    {
        $subject = $params[0] ?? 'mail:test';

        $email = service('email');
        $email->setTo('dev@work-tracker.loc');
        $email->setSubject($subject);
        $email->setMessage('Testovacia správa z Task Trackera.');

        if ($email->send(false) === false) {
            CLI::write($email->printDebugger(['headers']), 'red');

            return EXIT_ERROR;
        }

        CLI::write('Odoslané: ' . $subject, 'green');

        return EXIT_SUCCESS;
    }
}
