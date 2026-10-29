<?php

declare(strict_types=1);

namespace App\Libraries\Delivery;

use App\Libraries\Audit\AuditLogger;
use App\Libraries\Invoicing\DocumentFilename;
use App\Libraries\Invoicing\DocumentIntegrityError;
use App\Libraries\Invoicing\DocumentStore;
use App\Libraries\Invoicing\InvoiceIssuer;
use App\Libraries\Mail\AppMailer;
use App\Libraries\Mail\OutgoingMessage;
use App\Libraries\Master\CcEmails;
use App\Libraries\Platform\Transaction;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use InvalidArgumentException;

/**
 * Odoslanie faktury a vykazu e-mailom (MAIL-01, INV-06). Prilohy su vzdy presne ulozene bajty z invoice_documents
 * (nikdy sa nerenderuju znova, D-07). SMTP bezi MIMO transakcie, zaznam a audit vzniknu az po uspechu (D-04, D-10).
 * Opakovany request_key nic neposle a vrati predchadzajuci vysledok (D-11).
 */
class InvoiceMailer
{
    public const MAX_RECIPIENTS         = 10;
    public const MAX_SUBJECT            = 200;
    public const MAX_BODY               = 4000;
    public const REQUEST_KEY_CONSTRAINT = 'invoice_sends_request_key_key';
    private const KINDS                 = ['invoice', 'resend', 'reminder'];

    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly AppMailer $mailer;
    private readonly AuditLogger $audit;
    private readonly DocumentStore $store;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?AppMailer $mailer = null, ?AuditLogger $audit = null, ?DocumentStore $store = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->mailer = $mailer ?? new AppMailer();
        $this->audit  = $audit ?? new AuditLogger($this->db);
        $this->store  = $store ?? new DocumentStore($this->db);
    }

    /**
     * Navrh e-mailu (adresati, predmet, text zo sablony v jazyku dokladu, prilohy) bez odoslania.
     *
     * @return array{invoice: array<string, mixed>, language: string, to: list<string>, cc: list<string>, subject: string, body: string, sender: array{from_email: string, from_name: string, reply_to: ?string, bcc: list<string>}, attachments: list<array{kind: string, name: string, size: int, sha256: string}>, client_emails: list<string>, fallback: bool}
     *
     * @throws SendRejected
     */
    public function draft(int $invoiceId, string $kind): array
    {
        $invoice = $this->loadInvoice($invoiceId);
        $lang    = (string) $invoice['doc_language'];
        $client  = $this->db->query('SELECT invoice_email, cc_emails FROM clients WHERE id = :id:', ['id' => (int) $invoice['client_id']])->getRowArray();
        $email   = trim((string) ($client['invoice_email'] ?? ''));
        $cc      = $this->decodeList($client['cc_emails'] ?? '[]');

        $template = (new EmailTemplateService($this->db, $this->audit))->effective($kind === 'reminder' ? 'reminder' : 'invoice', $lang);
        $rendered = (new TemplateRenderer())->render($template['subject'], $template['body'], TemplateContext::values($invoice, service('clock')->today()));

        $attachments = [];

        foreach ($kind === 'reminder' ? ['invoice'] : ['invoice', 'report'] as $docKind) {
            $meta = $this->store->meta($invoiceId, $docKind);

            if ($meta !== null) {
                $attachments[] = [
                    'kind'   => $docKind,
                    'name'   => DocumentFilename::for($docKind, $lang, (string) $invoice['number']),
                    'size'   => $meta['byte_size'],
                    'sha256' => $meta['sha256'],
                ];
            }
        }

        return [
            'invoice'       => $invoice,
            'language'      => $lang,
            'to'            => $email !== '' ? [$email] : [],
            'cc'            => $cc,
            'subject'       => $rendered['subject'],
            'body'          => $rendered['text'],
            'sender'        => (new SenderIdentity($this->db))->resolve((int) $invoice['entity_id']),
            'attachments'   => $attachments,
            'client_emails' => array_merge($email !== '' ? [$email] : [], $cc),
            'fallback'      => $template['fallback'],
        ];
    }

    /**
     * @throws SendRejected
     */
    public function send(SendCommand $cmd): SendResult
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $cmd->requestKey) !== 1) {
            throw new InvalidArgumentException('Invalid request key.');
        }

        // 1. idempotencia (D-11)
        $previous = $this->findByRequestKey($cmd->requestKey);

        if ($previous !== null) {
            return $previous;
        }

        // 2. validacia nedoveryhodneho vstupu (T-07-13, T-07-17a)
        $this->validate($cmd);

        // 3. faktura a navrh
        $draft   = $this->draft($cmd->invoiceId, $cmd->kind);
        $invoice = $draft['invoice'];

        // 4. odosielatel
        $sender = $draft['sender'];

        try {
            SenderIdentity::assertAllowed($sender['from_email']);
        } catch (SenderRejected) {
            throw new SendRejected('sender_invalid');
        }

        // 5. prilohy: iba ulozene bajty (T-07-14)
        $attachments = $this->loadAttachments($cmd, $invoice, $draft['language']);

        // 6. sprava
        $messageId = $this->mailer->messageIdFor();
        $message   = new OutgoingMessage(
            kind: $cmd->kind,
            fromEmail: $sender['from_email'],
            fromName: $sender['from_name'],
            replyTo: $sender['reply_to'],
            to: $cmd->to,
            cc: $cmd->cc,
            bcc: $sender['bcc'],
            subject: $cmd->subject,
            textBody: $cmd->body,
            htmlBody: TemplateRenderer::html($cmd->body),
            attachments: array_map(static fn (array $a): array => ['name' => $a['name'], 'bytes' => $a['bytes'], 'mime' => 'application/pdf'], $attachments),
            messageId: $messageId,
            inReplyTo: null,
        );

        // 7. SMTP mimo transakcie (D-04, T-07-20)
        $outcome = $this->mailer->sendMessage($message);

        if (! $outcome->ok) {
            throw new SendRejected('smtp_failed');
        }

        // 8. zaznam az po uspechu (D-10)
        $counts = $this->countsAsSent($cmd, $draft['client_emails']);
        $row    = [
            'invoice_id'     => $cmd->invoiceId,
            'kind'           => $cmd->kind,
            'sent_at'        => service('clock')->now()->format('Y-m-d H:i:sP'),
            'recipients'     => json_encode(['to' => $cmd->to, 'cc' => $cmd->cc], JSON_THROW_ON_ERROR),
            'bcc'            => json_encode($sender['bcc'], JSON_THROW_ON_ERROR),
            'from_email'     => $sender['from_email'],
            'from_name'      => $sender['from_name'],
            'reply_to'       => $sender['reply_to'],
            'subject'        => $cmd->subject,
            'body_sha256'    => hash('sha256', $cmd->body),
            'attachments'    => json_encode(array_map(static fn (array $a): array => ['kind' => $a['kind'], 'name' => $a['name'], 'sha256' => $a['sha256'], 'size' => strlen($a['bytes'])], $attachments), JSON_THROW_ON_ERROR),
            'message_id'     => $messageId,
            'smtp_response'  => $outcome->smtpResponse,
            'transport'      => $outcome->transport,
            'counts_as_sent' => $counts ? 'true' : 'false',
            'request_key'    => $cmd->requestKey,
            'created_at'     => service('clock')->now()->format('Y-m-d H:i:sP'),
            'created_by'     => $cmd->userId,
        ];

        try {
            $sendId = Transaction::run($this->db, function () use ($row, $cmd, $sender, $counts, $messageId, $outcome): int {
                $id = $this->insertSend($row);
                $this->audit->record('invoice_send', $id, 'create', null, [
                    'invoice_id'     => $cmd->invoiceId,
                    'kind'           => $cmd->kind,
                    'to'             => $cmd->to,
                    'cc'             => $cmd->cc,
                    'bcc'            => $sender['bcc'],
                    'subject'        => $cmd->subject,
                    'message_id'     => $messageId,
                    'transport'      => $outcome->transport,
                    'counts_as_sent' => $counts,
                ]);

                return $id;
            });
        } catch (DatabaseException $e) {
            if (InvoiceIssuer::isUniqueViolation($e, self::REQUEST_KEY_CONSTRAINT)) {
                $existing = $this->findByRequestKey($cmd->requestKey);

                if ($existing !== null) {
                    return $existing;
                }
            }

            log_message('critical', sprintf('mail.sent_unrecorded invoice=%d message_id=%s', $cmd->invoiceId, $messageId));

            throw new SendRejected('record_failed');
        }

        return new SendResult($sendId, $messageId, $outcome->transport, $cmd->to, $cmd->cc, $counts, false);
    }

    /**
     * Historia odoslani faktury od najnovsieho.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $invoiceId): array
    {
        $rows = $this->db->query(
            'SELECT s.id, s.kind, s.sent_at, s.recipients, s.bcc, s.from_email, s.from_name, s.reply_to, s.subject, s.attachments, s.message_id,
                    s.transport, s.counts_as_sent, s.note, s.created_by, (v.id IS NOT NULL) AS voided, v.reason AS void_reason, v.voided_at
             FROM invoice_sends s LEFT JOIN invoice_send_voids v ON v.send_id = s.id
             WHERE s.invoice_id = :i: ORDER BY s.sent_at DESC, s.id DESC',
            ['i' => $invoiceId],
        )->getResultArray();

        foreach ($rows as &$r) {
            $r['recipients']     = json_decode((string) $r['recipients'], true, 512, JSON_THROW_ON_ERROR);
            $r['bcc']            = json_decode((string) $r['bcc'], true, 512, JSON_THROW_ON_ERROR);
            $r['attachments']    = json_decode((string) $r['attachments'], true, 512, JSON_THROW_ON_ERROR);
            $r['counts_as_sent'] = in_array($r['counts_as_sent'], [true, 't', 'true', 1, '1'], true);
            $r['voided']         = in_array($r['voided'], [true, 't', 'true', 1, '1'], true);
        }
        unset($r);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function insertSend(array $row): int
    {
        $result = $this->db->query(
            'INSERT INTO invoice_sends (invoice_id, kind, sent_at, recipients, bcc, from_email, from_name, reply_to, subject, body_sha256, attachments,
                    message_id, smtp_response, transport, counts_as_sent, request_key, created_at, created_by)
             VALUES (:invoice_id:, :kind:, :sent_at:, CAST(:recipients: AS jsonb), CAST(:bcc: AS jsonb), :from_email:, :from_name:, :reply_to:, :subject:, :body_sha256:,
                    CAST(:attachments: AS jsonb), :message_id:, :smtp_response:, :transport:, CAST(:counts_as_sent: AS boolean), CAST(:request_key: AS uuid), :created_at:, :created_by:)
             RETURNING id',
            $row,
        );

        return (int) $result->getRow()->id;
    }

    /**
     * @throws SendRejected
     */
    private function validate(SendCommand $cmd): void
    {
        if (! in_array($cmd->kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Invalid send kind.');
        }

        if ($cmd->to === []) {
            throw new SendRejected('no_recipients');
        }

        if (count($cmd->to) + count($cmd->cc) > self::MAX_RECIPIENTS) {
            throw new SendRejected('too_many_recipients');
        }

        foreach (['to' => $cmd->to, 'cc' => $cmd->cc] as $field => $list) {
            foreach ($list as $address) {
                if ($address !== trim($address) || CcEmails::validate([$address]) !== null) {
                    throw new SendRejected('invalid_address', $field);
                }
            }
        }

        if (trim($cmd->subject) === '' || trim($cmd->body) === '') {
            throw new SendRejected('empty_content');
        }

        if (mb_strlen($cmd->subject) > self::MAX_SUBJECT || mb_strlen($cmd->body) > self::MAX_BODY) {
            throw new SendRejected('too_long');
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SendRejected
     */
    private function loadInvoice(int $invoiceId): array
    {
        $row = $this->db->query(
            'SELECT id, origin, type, number, doc_language, snapshot_schema, snapshot, client_id, entity_id, total, currency, issue_date, due_date, billing_month
             FROM invoices WHERE id = :id:',
            ['id' => $invoiceId],
        )->getRowArray();

        if ($row === null) {
            throw new SendRejected('not_found');
        }

        if ($row['origin'] === 'external') {
            throw new SendRejected('external');
        }

        if ($row['type'] !== 'invoice') {
            throw new SendRejected('not_sendable');
        }

        $row['snapshot'] = json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
    }

    /**
     * @param array<string, mixed> $invoice
     *
     * @return list<array{kind: string, name: string, bytes: string, sha256: string}>
     *
     * @throws SendRejected
     */
    private function loadAttachments(SendCommand $cmd, array $invoice, string $lang): array
    {
        $out = [];

        try {
            foreach ($cmd->kind === 'reminder' ? ['invoice'] : ['invoice', 'report'] as $kind) {
                $doc = $this->store->read($cmd->invoiceId, $kind);

                if ($doc === null) {
                    if ($kind === 'report' && (int) $invoice['snapshot_schema'] < 2) {
                        continue;
                    }

                    throw new DocumentIntegrityError('Missing stored document: ' . $kind);
                }

                $out[] = ['kind' => $kind, 'name' => DocumentFilename::for($kind, $lang, (string) $invoice['number']), 'bytes' => $doc->bytes, 'sha256' => $doc->sha256];
            }
        } catch (DocumentIntegrityError $e) {
            log_message('critical', sprintf('mail.attachments_failed invoice=%d reason=%s', $cmd->invoiceId, $e->getMessage()));

            throw new SendRejected('attachments_failed');
        }

        return $out;
    }

    /**
     * @param list<string> $clientEmails
     */
    private function countsAsSent(SendCommand $cmd, array $clientEmails): bool
    {
        if (! in_array($cmd->kind, ['invoice', 'resend'], true)) {
            return false;
        }

        $known = array_map('mb_strtolower', $clientEmails);

        foreach (array_merge($cmd->to, $cmd->cc) as $address) {
            if (in_array(mb_strtolower($address), $known, true)) {
                return true;
            }
        }

        return false;
    }

    private function findByRequestKey(string $key): ?SendResult
    {
        $row = $this->db->query(
            'SELECT id, message_id, transport, recipients, counts_as_sent FROM invoice_sends WHERE request_key = CAST(:k: AS uuid)',
            ['k' => $key],
        )->getRowArray();

        if ($row === null) {
            return null;
        }

        $recipients = json_decode((string) $row['recipients'], true, 512, JSON_THROW_ON_ERROR);

        return new SendResult(
            (int) $row['id'],
            (string) $row['message_id'],
            (string) $row['transport'],
            array_values(array_map('strval', (array) ($recipients['to'] ?? []))),
            array_values(array_map('strval', (array) ($recipients['cc'] ?? []))),
            in_array($row['counts_as_sent'], [true, 't', 'true', 1, '1'], true),
            true,
        );
    }

    /**
     * @return list<string>
     */
    private function decodeList(mixed $json): array
    {
        $list = json_decode((string) $json, true);

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }
}
