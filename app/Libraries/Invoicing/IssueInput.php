<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * Whitelist a validacia vstupu vystavenia faktury (POST). Sumy ani cislo sa z formulara nikdy neprijimaju,
 * pocita ich iba server.
 */
final class IssueInput
{
    public const SCALARS    = ['client_id', 'billing_month', 'entity_id', 'preview_token', 'request_key', 'issue_date', 'delivery_date', 'due_date', 'confirm_backdate', 'payment_method', 'payment_note'];
    private const MAX_PARTS = 500;

    /**
     * @return array{client_id: string, billing_month: string, entity_id: string, preview_token: string, request_key: string, issue_date: string, delivery_date: string, due_date: string, confirm_backdate: string, payment_method: string, payment_note: string, part_ids: list<string>}
     */
    public static function fromPost(IncomingRequest $request): array
    {
        $out = [];

        foreach (self::SCALARS as $field) {
            $value       = $request->getPost($field);
            $out[$field] = is_string($value) ? trim($value) : '';
        }

        $ids   = $request->getPost('part_ids');
        $parts = [];

        if (is_array($ids)) {
            foreach ($ids as $id) {
                if (is_string($id)) {
                    $parts[] = trim($id);
                }
            }
        }

        $out['part_ids'] = $parts;

        return $out;
    }

    /**
     * @param array<string, mixed> $v
     *
     * @return array<string, string>
     */
    public static function validate(array $v): array
    {
        $errors  = [];
        $invalid = lang('Invoicing.errorSelection');
        $str     = static fn (string $k): string => is_string($v[$k] ?? null) ? $v[$k] : '';

        foreach (['client_id', 'entity_id'] as $field) {
            if (preg_match('/^\d{1,18}$/D', $str($field)) !== 1) {
                $errors[$field] = $invalid;
            }
        }

        $parts = is_array($v['part_ids'] ?? null) ? $v['part_ids'] : [];

        if ($parts === [] || count($parts) > self::MAX_PARTS) {
            $errors['part_ids'] = $invalid;
        } else {
            foreach ($parts as $id) {
                if (! is_string($id) || preg_match('/^\d{1,18}$/D', $id) !== 1) {
                    $errors['part_ids'] = $invalid;

                    break;
                }
            }
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $str('preview_token')) !== 1) {
            $errors['preview_token'] = $invalid;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $str('request_key')) !== 1) {
            $errors['request_key'] = $invalid;
        }

        if ($str('billing_month') === '') {
            $errors['billing_month'] = $invalid;
        }

        foreach (['issue_date', 'delivery_date', 'due_date'] as $field) {
            if ($str($field) === '') {
                $errors[$field] = lang('Invoicing.errorDates');
            }
        }

        if ($str('payment_method') !== '' && ! in_array($str('payment_method'), IssueCommand::PAYMENT_METHODS, true)) {
            $errors['payment_method'] = lang('Invoicing.errorPaymentMethod');
        }

        if (mb_strlen(self::normalizeNote($str('payment_note')) ?? '') > IssueCommand::NOTE_MAX) {
            $errors['payment_note'] = lang('Invoicing.errorPaymentNote');
        }

        $shared = PreviewInput::validate([
            'month'         => $str('billing_month'),
            'issue_date'    => $str('issue_date'),
            'delivery_date' => $str('delivery_date'),
            'due_date'      => $str('due_date'),
        ]);

        if (isset($shared['month'])) {
            $errors['billing_month'] = $shared['month'];
        }

        foreach (['issue_date', 'delivery_date', 'due_date'] as $field) {
            if (isset($shared[$field])) {
                $errors[$field] = $shared[$field];
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $v zvalidovany vstup
     */
    public static function toCommand(array $v, ?int $userId): IssueCommand
    {
        $str   = static fn (string $k): string => is_string($v[$k] ?? null) ? $v[$k] : '';
        $parts = array_values(array_unique(array_map('intval', is_array($v['part_ids'] ?? null) ? $v['part_ids'] : [])));
        sort($parts);

        return new IssueCommand(
            (int) $str('client_id'),
            $str('billing_month'),
            (int) $str('entity_id'),
            $str('issue_date'),
            $str('delivery_date'),
            $str('due_date'),
            $parts,
            $str('preview_token'),
            $str('request_key'),
            $str('confirm_backdate') === '1',
            $userId,
            $str('payment_method') === '' ? 'transfer' : $str('payment_method'),
            self::normalizeNote($str('payment_note')),
        );
    }

    /**
     * \r\n na \n, TAB na medzeru, trim, prazdny retazec na null, ostatne riadiace znaky (okrem \n) sa odstrania.
     * Pravidla sa zhoduju s IssueCommand, takze zvalidovana poznamka nikdy nezhodi prikaz (WR-01).
     */
    public static function normalizeNote(string $note): ?string
    {
        $note = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $note);
        $note = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $note);
        $note = trim($note);

        return $note === '' ? null : $note;
    }
}
