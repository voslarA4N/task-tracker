<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Libraries\Audit\AuditDiff;
use App\Libraries\Audit\AuditFilter;
use App\Libraries\Audit\AuditQueries;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use DateMalformedStringException;
use IntlDateFormatter;

/**
 * Nastavenia, Denník zmien: čítanie audit_log (AUD-02, D-13). Iba GET, bez zápisu.
 */
final class Audit extends BaseController
{
    private const ENTITY_LINKS = ['invoice' => '/invoices/', 'task' => '/tasks/'];

    public function index(): ResponseInterface
    {
        $queries = new AuditQueries();
        $types   = $queries->entityTypes();
        $actions = $queries->actions();
        $filter  = AuditFilter::fromQuery($this->request->getGet(), $types, $actions);
        $result  = $queries->list($filter);

        $rows = array_map(function (array $r): array {
            $type              = (string) $r['entity_type'];
            $r['time_label']   = $this->formatTime((string) $r['occurred_at']);
            $r['entity_label'] = self::label('entity_', $type);
            $r['action_label'] = self::label('action_', (string) $r['action']);
            $r['entity_href']  = isset(self::ENTITY_LINKS[$type]) ? self::ENTITY_LINKS[$type] . (int) $r['entity_id'] : null;

            return $r;
        }, $result['rows']);

        $entityOptions = [];

        foreach ($types as $t) {
            $entityOptions[$t] = self::label('entity_', $t);
        }

        $actionOptions = [];

        foreach ($actions as $a) {
            $actionOptions[$a] = self::label('action_', $a);
        }

        $body = htmx_view('settings/audit/_list', [
            'rows'          => $rows,
            'hasMore'       => $result['hasMore'],
            'filter'        => $filter,
            'entityOptions' => $entityOptions,
            'actionOptions' => $actionOptions,
            'users'         => $queries->users(),
            'mainWide'      => true,
        ], 'settings/audit/index');

        return $this->response->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    public function show(int $id): ResponseInterface
    {
        $row = (new AuditQueries())->find($id);

        if ($row === null) {
            throw PageNotFoundException::forPageNotFound(lang('AuditLog.notFound'));
        }

        $type = (string) $row['entity_type'];
        $body = htmx_view('settings/audit/_detail_sheet', [
            'row'          => $row,
            'fields'       => AuditDiff::fields($row['before_data'], $row['after_data']),
            'time_label'   => $this->formatTime((string) $row['occurred_at']),
            'entity_label' => self::label('entity_', $type),
            'action_label' => self::label('action_', (string) $row['action']),
            'mainWide'     => false,
        ], 'settings/audit/show');

        return $this->response->setHeader('Vary', 'HX-Request')->setBody($body);
    }

    /**
     * Popisok z lang súboru, inak surový kľúč (rozhodnutie orchestrátora 3).
     */
    private static function label(string $prefix, string $key): string
    {
        $path = 'AuditLog.' . $prefix . $key;
        $text = lang($path);

        return $text === $path ? $key : $text;
    }

    private function formatTime(string $timestamp): string
    {
        try {
            $unix = service('clock')->now()->modify($timestamp)->getTimestamp();
        } catch (DateMalformedStringException) {
            return $timestamp;
        }

        $formatter = new IntlDateFormatter(
            $this->request->getLocale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            config('App')->appTimezone,
            IntlDateFormatter::GREGORIAN,
            'd. M. yyyy HH:mm',
        );
        $out = $formatter->format($unix);

        return $out === false ? $timestamp : $out;
    }
}
