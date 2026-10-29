<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

use CodeIgniter\Email\Email;

/**
 * CI4 Email s vlastnym Message-ID (D-12), SMTP obalkou (MAIL FROM) z uctu aplikacie (D-27)
 * a zachytenou odpovedou servera na DATA.
 */
class AppEmail extends Email
{
    private ?string $customMessageId = null;
    private string $envelopeFrom     = '';
    private ?string $lastDataReply   = null;
    private bool $awaitingDataReply  = false;

    public function setMessageId(string $id): static
    {
        $this->customMessageId = $id;

        return $this;
    }

    public function setEnvelopeFrom(string $address): static
    {
        $this->envelopeFrom = $address;

        return $this;
    }

    public function smtpReply(): ?string
    {
        return $this->lastDataReply;
    }

    protected function getMessageID()
    {
        return $this->customMessageId ?? parent::getMessageID();
    }

    protected function sendCommand($cmd, $data = '')
    {
        if ($cmd === 'from' && $this->envelopeFrom !== '') {
            return parent::sendCommand('from', $this->envelopeFrom);
        }

        return parent::sendCommand($cmd, $data);
    }

    protected function sendData($data)
    {
        $this->awaitingDataReply = ($data === $this->newline . '.');

        return parent::sendData($data);
    }

    protected function getSMTPData()
    {
        $reply = parent::getSMTPData();

        if ($this->awaitingDataReply) {
            $this->lastDataReply     = $reply;
            $this->awaitingDataReply = false;
        }

        return $reply;
    }
}
