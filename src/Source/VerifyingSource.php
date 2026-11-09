<?php

namespace GlpiPlugin\Backupinfo\Source;

/**
 * A source that can import restore-point verifications as restore-test records (0.3.0).
 * Vinchin implements this; Veeam does not in 0.5.0 (SureBackup over REST is unconfirmed).
 */
interface VerifyingSource
{
    /**
     * Verification inputs for one timepoint of one computer, mapped from the source's
     * verification rows. Never throws; a refused read gives an error line and empty inputs.
     *
     * @return array{inputs: list<array<string,mixed>>, error: string}
     */
    public function verificationInputs(string $timepointUuid, int $computerId): array;
}
