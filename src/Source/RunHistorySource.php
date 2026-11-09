<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * A source whose backup runs can be stored as audit evidence (0.3.0). None of these methods throws:
 * a failed call is returned as an error line (no secret) and the sync goes on.
 */
interface RunHistorySource
{
    /**
     * Runs newest first. Paging stops after a page whose runs $pageKnown says are all stored already,
     * or after $maxPages pages.
     *
     * @param callable(list<string>): bool $pageKnown receives the page's history uuids
     * @return array{runs: list<Run>, errors: list<string>}
     */
    public function runs(callable $pageKnown, int $maxPages, int $pageSize = 100): array;

    /**
     * Per-object result of one run.
     *
     * @return array{items: list<array{vm_name: string, status: string, error: string}>, error: string}
     */
    public function runItems(string $historyUuid): array;

    /**
     * Failure reasons for the failed runs. Keys of `reasons` are historyUuid. Never throws;
     * a refused source gives an error line and empty reasons.
     *
     * @param list<Run> $failedRuns
     * @return array{reasons: array<string, string>, error: string}
     */
    public function failureReasons(array $failedRuns): array;
}
