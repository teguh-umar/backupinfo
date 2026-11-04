<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * One backup run (a job history entry). Times are the source's wall-clock strings, normalised to
 * `Y-m-d H:i:s` and never shifted. status: success | failed | aborted | abnormal.
 */
final class Run
{
    public const SUCCESS  = 'success';
    public const FAILED   = 'failed';
    public const ABORTED  = 'aborted';
    public const ABNORMAL = 'abnormal';

    public function __construct(
        public readonly string $historyUuid,
        public readonly string $jobName,
        public readonly string $jobUuid,
        public readonly ?string $start,
        public readonly ?string $finish,
        public readonly string $status,
        public readonly string $statusText,
        public readonly string $sizeText,
        public readonly ?int $durationS,
    ) {
    }
}
