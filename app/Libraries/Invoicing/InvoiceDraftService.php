<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Libraries\Fx\FxInput;
use App\Libraries\Fx\FxQuote;
use App\Libraries\Fx\FxService;
use App\Libraries\Fx\RateNotFound;
use App\Libraries\Fx\RateUnavailable;
use App\Libraries\Fx\UnsupportedPair;
use App\Libraries\Invoicing\Qr\PolishZbpQr;
use App\Libraries\Invoicing\Qr\QrPaymentFailed;
use App\Libraries\Master\EntityDirectory;
use App\Libraries\Master\EntityVersionResolver;
use App\Libraries\Master\InvalidSeriesFormat;
use App\Libraries\Master\LegalProfileResolver;
use App\Libraries\Master\LegalTextMissing;
use App\Libraries\Master\SeriesFormat;
use App\Libraries\Master\VersionNotFound;
use App\Libraries\Work\BillingMonth;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Nahlad "Fakturacia mesiaca": iba citanie, bez transakcie a bez zamkov. Pouziva rovnaky SnapshotBuilder
 * a InvoiceCalculator ako vystavenie (D-02), takze nahlad a faktura davaju rovnake riadky a sucet.
 */
final class InvoiceDraftService
{
    /**
     * @var BaseConnection<mixed, mixed>
     */
    private readonly BaseConnection $db;

    private readonly InvoiceParts $parts;
    private readonly InvoiceGuards $guards;
    private readonly SnapshotBuilder $builder;
    private readonly EntityDirectory $entities;
    private readonly EntityVersionResolver $versions;
    private readonly LegalProfileResolver $legal;
    private readonly FxService $fx;

    /**
     * @param BaseConnection<mixed, mixed>|null $db
     */
    public function __construct(?BaseConnection $db = null, ?FxService $fx = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->parts    = new InvoiceParts($this->db);
        $this->guards   = new InvoiceGuards($this->db);
        $this->builder  = new SnapshotBuilder($this->db);
        $this->entities = new EntityDirectory($this->db);
        $this->versions = new EntityVersionResolver($this->db);
        $this->legal    = new LegalProfileResolver($this->db);
        $this->fx       = $fx ?? new FxService($this->db);
    }

    /**
     * Najstarsi mesiac s nezamknutymi castami klienta, inak predchadzajuci mesiac.
     */
    public function defaultMonth(int $clientId): string
    {
        $row = $this->db->query(
            'SELECT min(a.billing_month) AS m FROM billing_allocations a JOIN tasks t ON t.id = a.task_id
             WHERE t.client_id = :c: AND a.invoice_id IS NULL',
            ['c' => $clientId],
        )->getRowArray();

        if ($row !== null && $row['m'] !== null) {
            return (string) $row['m'];
        }

        return BillingMonth::previous(BillingMonth::current());
    }

    public function draft(int $clientId, string $month, ?int $entityId, ?string $issueDate, ?string $deliveryDate, ?string $dueDate, ?string $paymentMethod = null, ?string $paymentNote = null, bool $noteDirty = false, bool $fxRetry = false, ?FxInput $fxInput = null): Draft
    {
        $clock = service('clock');

        if (BillingMonth::parse($month) === null) {
            throw new InvalidArgumentException('Invalid billing month.');
        }

        $client = $this->db->query('SELECT * FROM clients WHERE id = :c:', ['c' => $clientId])->getRowArray();

        if ($client === null) {
            throw new InvalidArgumentException('Client not found.');
        }

        $issue    = $this->validDate($issueDate) ?? $clock->today();
        $delivery = $this->validDate($deliveryDate) ?? $this->lastDayOfMonth($month);
        $entities = $this->entities->activeAt($issue);
        $errors   = [];

        $selected = null;

        foreach ($entities as $e) {
            if ($entityId !== null && $e['id'] === $entityId) {
                $selected = $e['id'];
            }
        }

        $selected ??= $entities[0]['id'] ?? null;

        $entity = null;

        if ($selected === null) {
            $errors[] = ['reason' => 'entity_inactive', 'context' => ['date' => $issue]];
        } else {
            $entity = $this->db->query('SELECT * FROM entities WHERE id = :e:', ['e' => $selected])->getRowArray();
        }

        $due = $this->validDate($dueDate) ?? $this->defaultDue($client, $selected, $issue);

        $template = ($client['portal_url_template'] ?? null) === null ? null : (string) $client['portal_url_template'];
        $parts    = $this->parts->unlocked($clientId, $month, $template);
        $locked   = $this->parts->hasLocked($clientId, $month);

        $lines    = [];
        $total    = '0.00';
        $hours    = '0.00';
        $count    = 0;
        $currency = null;
        $token    = null;
        $snapshot = null;
        $fxState  = null;

        if ($parts !== []) {
            try {
                $calc     = (new InvoiceCalculator())->compute($parts, $this->vatCode($client, $entity, $issue));
                $lines    = $calc['lines'];
                $total    = $calc['total'];
                $hours    = $calc['hours'];
                $count    = $calc['line_count'];
                $currency = $calc['currency'];

                if ($entity !== null) {
                    $invoiceCcy = $this->guards->partsCurrency($parts);
                    $ctx        = $this->fx->context((string) $entity['country'], trim((string) $entity['currency']), $invoiceCcy, $issue, $delivery);
                    $fetched    = null;
                    $quote      = null;
                    $state      = 'ok';

                    // siet sa vola iba tu (nahlad, D-07), nikdy v transakcii vystavenia
                    if ($ctx !== null) {
                        try {
                            $fetched = $this->fx->resolve($ctx, $fxRetry || ($fxInput !== null && $fxInput->retry));
                            $quote   = $fetched;
                        } catch (UnsupportedPair) {
                            $state = 'unsupported';
                        } catch (RateNotFound|RateUnavailable) {
                            $state = 'unavailable';
                        }
                    }

                    $fxFields      = ['fx_mode' => '', 'fx_rate' => '', 'fx_date' => '', 'fx_anchor' => ''];
                    $fxFieldErrors = [];
                    $anchorChanged = false;

                    if ($ctx !== null && $fxInput !== null && $fxInput->mode === 'manual') {
                        if ($fxInput->anchor !== null && $fxInput->anchor !== $ctx->anchor) {
                            // kotviaci datum sa zmenil: rucny kurz sa zahodi (D-08)
                            $anchorChanged = true;
                        } elseif ($fxInput->errors !== []) {
                            $state         = 'invalid';
                            $quote         = null;
                            $fxFieldErrors = $fxInput->errors;
                            $fxFields      = ['fx_mode' => 'manual', 'fx_rate' => $fxInput->rawRate, 'fx_date' => $fxInput->rawDate, 'fx_anchor' => $ctx->anchor];
                        } else {
                            $quote = FxQuote::manual((string) $fxInput->rate, $fxInput->date ?? $ctx->bound, $ctx->direction, $ctx->dateBasis, $ctx->dayRule, $ctx->anchor, $ctx->entityCurrency, $ctx->invoiceCurrency, $fetched?->toRateQuote());
                            $state    = 'manual';
                            $fxFields = ['fx_mode' => 'manual', 'fx_rate' => (string) $fxInput->rate, 'fx_date' => $fxInput->date ?? '', 'fx_anchor' => $ctx->anchor];
                        }
                    }

                    if ($ctx !== null) {
                        $fxState = [
                            'state'            => $state,
                            'source'           => $ctx->source,
                            'date_basis'       => $ctx->dateBasis,
                            'day_rule'         => $ctx->dayRule,
                            'anchor'           => $ctx->anchor,
                            'bound'            => $ctx->bound,
                            'direction'        => $ctx->direction,
                            'entity_currency'  => $ctx->entityCurrency,
                            'invoice_currency' => $ctx->invoiceCurrency,
                            'quote'            => null,
                            'fields'           => $fxFields,
                            'errors'           => $fxFieldErrors,
                            'fetched'          => $fetched?->core(),
                            'anchor_changed'   => $anchorChanged,
                        ];
                    }

                    try {
                        $snapshot = $this->builder->build($client, $entity, $parts, $month, $issue, $delivery, $due, null, 'transfer', null, $quote);
                    } catch (IssueRejected $e) {
                        if ($e->reason !== 'fx_overflow') {
                            throw $e;
                        }

                        $errors[] = ['reason' => $e->reason, 'context' => $e->context];
                        $state    = 'overflow';
                        $snapshot = $this->builder->build($client, $entity, $parts, $month, $issue, $delivery, $due, null);
                    }

                    if ($fxState !== null) {
                        $fxState['state'] = $state;
                        $fxState['quote'] = in_array($state, ['ok', 'manual'], true) ? $snapshot['fx'] : null;
                    }

                    // bez kurzu sa kopia zostavi iba na zobrazenie, vystavit sa nedá (token ostane null)
                    $token = $ctx !== null && ! in_array($state, ['ok', 'manual'], true) ? null : InvoiceCalculator::previewToken($snapshot);
                }
            } catch (IssueRejected $e) {
                $errors[] = ['reason' => $e->reason, 'context' => $e->context];
            }
        }

        $backdate  = null;
        $seriesFmt = null;

        if ($selected !== null) {
            $series = $this->db->query("SELECT id, format FROM invoice_series WHERE entity_id = :e: AND kind = 'invoice'", ['e' => $selected])->getRowArray();

            if ($series !== null) {
                $backdate  = $this->guards->backdate((int) $series['id'], $issue);
                $seriesFmt = (string) $series['format'];
            }
        }

        $method      = $paymentMethod !== null && in_array($paymentMethod, IssueCommand::PAYMENT_METHODS, true) ? $paymentMethod : 'transfer';
        $texts       = is_array($snapshot) ? ($snapshot['legal']['texts'] ?? []) : [];
        $defaultNote = is_array($texts['invoice_note'] ?? null) ? $texts['invoice_note'] : null;
        $note        = $noteDirty ? IssueInput::normalizeNote((string) $paymentNote) : ($defaultNote['body'] ?? null);
        $provisional = (bool) ($texts['vat_note']['provisional'] ?? false) || (! $noteDirty && (bool) ($defaultNote['provisional'] ?? false));

        // Skusobny QR s najdlhsim moznym cislom radu: deterministicke zlyhanie (NIP, IBAN, suma, xz) sa ukaze uz v nahlade (WR-03).
        if (is_array($snapshot) && $method === 'transfer' && $seriesFmt !== null && $errors === []) {
            $probeNumber = self::longestNumber($seriesFmt, $issue);

            if ($probeNumber !== '') {
                $probe                      = $snapshot;
                $probe['payment']['method'] = $method;

                try {
                    $this->builder->withNumber($probe, $probeNumber);
                } catch (QrPaymentFailed $e) {
                    $errors[] = ['reason' => 'qr_failed', 'context' => ['qr_reason' => $e->reason]];
                }
            }
        }

        $zbpApplies       = is_array($snapshot) && $method === 'transfer' && PolishZbpQr::applies($snapshot);
        $zbpWarning       = $zbpApplies && $seriesFmt !== null && ! PolishZbpQr::titleFits(self::longestNumber($seriesFmt, $issue));
        $zbpAmountWarning = $zbpApplies && ! PolishZbpQr::amountFits((string) ($snapshot['totals']['total'] ?? ''));

        return new Draft(
            $clientId,
            $month,
            $selected,
            $issue,
            $delivery,
            $due,
            $lines,
            $total,
            $hours,
            $count,
            $currency,
            array_map(static fn (array $l): int => (int) $l['source_allocation_id'], $lines),
            $token,
            $snapshot,
            $backdate,
            $locked,
            $errors,
            $entities,
            $count > 0 && $errors === [] && $selected !== null && $token !== null,
            $method,
            $note,
            $noteDirty,
            $provisional,
            $zbpWarning,
            $zbpAmountWarning,
            $fxState,
        );
    }

    /**
     * Najdlhsie mozne cislo radu (len citanie formatu). Neplatny format sa ignoruje, vystavenie ho odmietne.
     */
    private static function longestNumber(string $format, string $issueDate): string
    {
        try {
            $parsed = SeriesFormat::parse($format);

            return $parsed->render($issueDate, $parsed->maxSequence());
        } catch (InvalidSeriesFormat) {
            return '';
        }
    }

    private function validDate(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return service('clock')->parseDate($iso)?->format('Y-m-d');
    }

    private function lastDayOfMonth(string $month): string
    {
        $first = service('clock')->parseDate($month . '-01');

        return $first instanceof DateTimeImmutable ? $first->modify('last day of this month')->format('Y-m-d') : $month . '-28';
    }

    /**
     * @param array<string, mixed> $client
     */
    private function defaultDue(array $client, ?int $entityId, string $issue): string
    {
        $days = $client['due_days'] === null ? null : (int) $client['due_days'];

        if ($days === null && $entityId !== null) {
            try {
                $days = (int) $this->versions->atDate($entityId, $issue)['due_days'];
            } catch (VersionNotFound) {
                $days = null;
            }
        }

        $base = service('clock')->parseDate($issue);

        return $base instanceof DateTimeImmutable ? $base->modify('+' . ($days ?? 0) . ' days')->format('Y-m-d') : $issue;
    }

    /**
     * @param array<string, mixed>      $client
     * @param array<string, mixed>|null $entity
     */
    private function vatCode(array $client, ?array $entity, string $issue): ?string
    {
        if ($entity === null) {
            return null;
        }

        try {
            $version = $this->versions->atDate((int) $entity['id'], $issue);

            return $this->legal->profile((string) $entity['country'], (string) $version['vat_regime'], (string) $client['billing_mode'])['vat_code'];
        } catch (LegalTextMissing|VersionNotFound) {
            return null;
        }
    }
}
