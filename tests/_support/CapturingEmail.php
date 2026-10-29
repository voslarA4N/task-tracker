<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\Mail\AppEmail;

/**
 * AppEmail bez siete: zachyti hlavicky a telo, ktore by islo na SMTP.
 */
final class CapturingEmail extends AppEmail
{
    public string $capturedHeaders = '';
    public string $capturedBody    = '';
    public bool $result            = true;

    protected function spoolEmail()
    {
        $this->capturedHeaders = $this->headerStr;
        $this->capturedBody    = $this->finalBody;

        return $this->result;
    }
}
