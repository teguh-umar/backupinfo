<?php

namespace GlpiPlugin\Backupinfo;

use Session;
use Toolbox;

class SuiteConfig
{
    /**
     * Section provider for the shared PGN Suite settings page (pgnsuite_config_section).
     * Returns null when the current user may not see the section.
     */
    public static function section(): ?array
    {
        if (!Session::haveRight(RightDefinition::CONFIG, READ)) {
            return null;
        }
        return [
            'title'  => __('Backup Info', 'backupinfo'),
            'icon'   => 'ti ti-database-export',
            'order'  => 500,
            'render' => self::class . '::render',
        ];
    }

    public static function render(): void
    {
        echo "<div class='card'>";
        echo "<div class='card-header'><h3 class='card-title'>Backup Info (Vinchin)</h3></div>";
        echo "<div class='card-body'>";
        echo "<p>Configure Vinchin API connection, sync schedule, and Excel import in <a href='"
            . htmlescape(Toolbox::getItemTypeSearchURL(Connection::class))
            . "'>Setup &gt; Backup Info &gt; Connections</a>.</p>";
        echo "</div></div>";
    }
}
