<?php

namespace GlpiPlugin\Backupinfo\Source;

final class Registry
{
    /** @return array<string, string> source type => label */
    public static function types(): array
    {
        return ['vinchin' => 'Vinchin'];
    }
}
