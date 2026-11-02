<?php

declare(strict_types=1);

namespace App\Libraries\Invoicing;

use App\Controllers\Invoicing;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Whitelist a validacia vstupu dobropisu (POST). Sumy, riadky ani kurz sa z formulara nikdy neprijimaju (D-20).
 */
final class CreditNoteInput
{
    public const FIELDS = ['issue_date', 'reason', 'correction_type', 'preview_token', 'request_key', 'confirm_backdate'];

    /**
     * @return array{issue_date: string, reason: string, correction_type: string, preview_token: string, request_key: string, confirm_backdate: string}
     */
    public static function fromPost(IncomingRequest $request): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            $value       = $request->getPost($field);
            $out[$field] = is_string($value) ? trim($value) : '';
        }

        $out['reason'] = self::normalizeReason($out['reason']);

        /** @var array{issue_date: string, reason: string, correction_type: string, preview_token: string, request_key: string, confirm_backdate: string} $out */
        return $out;
    }

    /**
     * \r\n na \n, TAB na medzeru, ostatne riadiace znaky okrem \n sa odstrania (pravidla CreditNoteCommand).
     */
    public static function normalizeReason(string $reason): string
    {
        $reason = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $reason);

        return trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $reason));
    }

    /**
     * @param array<string, mixed> $v
     *
     * @return array<string, string>
     */
    public static function validate(array $v, string $originalIssueDate, bool $hasFx): array
    {
        $errors = [];
        $str    = static fn (string $k): string => is_string($v[$k] ?? null) ? $v[$k] : '';

        $type = CorrectionType::tryFrom($str('correction_type'));

        if ($type === null || ($type === CorrectionType::FxError && ! $hasFx)) {
            $errors['correction_type'] = lang('CreditNotes.errorType');
        }

        $len = mb_strlen(trim($str('reason')));

        if ($len < 1) {
            $errors['reason'] = lang('CreditNotes.errorReasonRequired');
        } elseif ($len > CreditNoteCommand::REASON_MAX) {
            $errors['reason'] = lang('CreditNotes.errorReasonTooLong');
        }

        $date = $str('issue_date');

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $errors['issue_date'] = lang('CreditNotes.errorDate');
        } elseif ($date < $originalIssueDate) {
            $errors['issue_date'] = lang('CreditNotes.errorDateBeforeOriginal', [Invoicing::dateLabel($originalIssueDate)]);
        } else {
            $clock  = service('clock');
            $maxDay = $clock->parseDate($clock->today())?->modify('+' . PreviewInput::ISSUE_MAX_AHEAD_DAYS . ' days')->format('Y-m-d');

            if ($maxDay !== null && $date > $maxDay) {
                $errors['issue_date'] = lang('CreditNotes.errorIssueFuture', [PreviewInput::ISSUE_MAX_AHEAD_DAYS]);
            }
        }

        return $errors;
    }

    /**
     * Kontrola kluca nahladu a request_key pred vystavenim.
     *
     * @param array<string, mixed> $v
     *
     * @return array<string, string>
     */
    public static function validateKeys(array $v): array
    {
        $errors = [];
        $str    = static fn (string $k): string => is_string($v[$k] ?? null) ? $v[$k] : '';

        if (preg_match('/^[0-9a-f]{64}$/D', $str('preview_token')) !== 1) {
            $errors['preview_token'] = lang('CreditNotes.errorStale');
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $str('request_key')) !== 1) {
            $errors['request_key'] = lang('CreditNotes.errorStale');
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $v zvalidovany vstup
     */
    public static function toCommand(array $v, int $originalId, ?int $userId): CreditNoteCommand
    {
        $str = static fn (string $k): string => is_string($v[$k] ?? null) ? $v[$k] : '';

        return new CreditNoteCommand(
            $originalId,
            $str('issue_date'),
            $str('reason'),
            CorrectionType::from($str('correction_type')),
            $str('preview_token'),
            $str('request_key'),
            $str('confirm_backdate') === '1',
            $userId,
        );
    }
}
