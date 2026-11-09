<?php

namespace GlpiPlugin\Backupinfo\Veeam;

use GlpiPlugin\Backupinfo\Source\Run;
use GlpiPlugin\Backupinfo\Source\Time;

/**
 * Veeam session -> Run, and per-VM task session -> item. Unsure is never
 * success: a session still running (state !== Stopped) is not stored, and
 * an unknown result is abnormal.
 */
final class RunMapper
{
    public static function map(mixed $session): ?Run
    {
        if (!is_array($session)) {
            return null;
        }
        $id = trim((string) ($session['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        if (strcasecmp((string) ($session['state'] ?? ''), 'Stopped') !== 0) {
            return null;
        }
        $result = $session['result'] ?? null;

        return new Run(
            $id,
            trim((string) ($session['name'] ?? '')),
            trim((string) ($session['jobId'] ?? '')),
            Time::local($session['creationTime'] ?? null),
            Time::local($session['endTime'] ?? null),
            self::status($result),
            mb_substr((string) (is_array($result) ? ($result['result'] ?? '') : ''), 0, 64),
            '',
            self::duration($session['creationTime'] ?? null, $session['endTime'] ?? null),
        );
    }

    /**
     * Apply the Veeam status table to session result{result,isCanceled}.
     * Failed wins over cancel; isCanceled with Success/Warning/None is aborted;
     * anything unknown is abnormal.
     */
    public static function status(mixed $result): string
    {
        if (!is_array($result)) {
            return Run::ABNORMAL;
        }
        $res  = (string) ($result['result'] ?? '');
        $canc = (bool) ($result['isCanceled'] ?? false);
        if ($res === 'Failed') {
            return Run::FAILED;
        }
        if ($canc) {
            return Run::ABORTED;
        }
        if ($res === 'Success') {
            return Run::SUCCESS;
        }
        if ($res === 'Warning') {
            return Run::ABNORMAL;
        }
        return Run::ABNORMAL;
    }

    /**
     * Per-VM task -> item. Returns null when the VM name is empty.
     */
    public static function item(mixed $task): ?array
    {
        if (!is_array($task)) {
            return null;
        }
        $name = trim((string) ($task['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $result = $task['result'] ?? null;
        return [
            'vm_name' => $name,
            'status'  => self::status($result),
            'error'   => mb_substr(trim((string) (is_array($result) ? ($result['message'] ?? '') : '')), 0, 255),
        ];
    }

    /**
     * Failure reason for a run: first Failed task message, else first Warning
     * task message, else the session's result.message, else ''.
     */
    public static function reason(array $tasks, mixed $session): string
    {
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $result = $task['result'] ?? null;
            if (is_array($result) && (string) ($result['result'] ?? '') === 'Failed') {
                return (string) ($result['message'] ?? '');
            }
        }
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $result = $task['result'] ?? null;
            if (is_array($result) && (string) ($result['result'] ?? '') === 'Warning') {
                return (string) ($result['message'] ?? '');
            }
        }
        $sResult = $session['result'] ?? null;
        if (is_array($sResult)) {
            return (string) ($sResult['message'] ?? '');
        }
        return '';
    }

    /** finish - start in seconds when both exist and are >= 0, else null. */
    private static function duration(?string $start, ?string $end): ?int
    {
        if ($start === null || $end === null) {
            return null;
        }
        $ts = Time::epoch($start);
        $te = Time::epoch($end);
        if ($ts === null || $te === null || $te < $ts) {
            return null;
        }
        return $te - $ts;
    }
}
