<?php

namespace GlpiPlugin\Backupinfo\Source;

interface BackupSource
{
    /** @return list<BackupObject> */
    public function fetch(): array;
}
