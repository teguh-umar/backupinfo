<?php

namespace GlpiPlugin\Backupinfo;

/**
 * The user manual, linked from inside the plugin (suite rule 2026-11-09, hub §4; 0.5.0-rc1).
 * Source: docs/manual/backupinfo.md at the suite root (written by Copilot). tools/build_zip.py copies
 * it to docs/manual.md here, the one docs file that ships in the zip. It is rendered with GLPI's
 * bundled league/commonmark. Raw HTML is escaped and unsafe links are dropped, so the manual cannot
 * inject markup or script into a GLPI page. Reference: opmanager v1.3.0-rc4.
 */
final class Manual
{
    /** league/commonmark settings: no raw HTML, no javascript:/data: links. */
    public const CONVERTER_OPTIONS = ['html_input' => 'escape', 'allow_unsafe_links' => false];

    public static function path(): string
    {
        return dirname(__DIR__) . '/docs/manual.md';
    }

    /** The manual's text, or null when the file is not installed. */
    public static function read(): ?string
    {
        $path = self::path();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $text = file_get_contents($path);
        return $text === false ? null : $text;
    }

    /** The version the manual documents ("Versi didokumentasikan: 0.5.0" or "Version documented: 0.5.0"). */
    public static function versionOf(string $markdown): string
    {
        // Markdown emphasis around the label or the value is skipped: Copilot writes "**0.5.0**" (hub §4).
        return preg_match('/(?:Versi didokumentasikan|Version documented)[*_`\s]*:[*_`\s]*v?(\d+(?:\.\d+){1,2})/i', $markdown, $m) === 1 ? $m[1] : '';
    }

    /** True when the manual documents an older major.minor than the installed plugin (patch and rc ignored). */
    public static function lags(string $manualVersion, string $pluginVersion): bool
    {
        if ($manualVersion === '') {
            return false;
        }
        $minor = static fn(string $v): string => implode('.', array_slice(array_pad(explode('.', (string) preg_replace('/-.*$/', '', $v)), 2, '0'), 0, 2));
        return version_compare($minor($manualVersion), $minor($pluginVersion), '<');
    }

    /** Markdown to safe HTML. Without commonmark (never on GLPI 11) the text is shown escaped. */
    public static function render(string $markdown): string
    {
        if (class_exists(\League\CommonMark\GithubFlavoredMarkdownConverter::class)) {
            return (string) (new \League\CommonMark\GithubFlavoredMarkdownConverter(self::CONVERTER_OPTIONS))->convert($markdown);
        }
        return '<pre class="text-wrap">' . htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }

    /** The page body: a notice when missing, the version line or a lag warning, the manual. */
    public static function body(?string $markdown, string $pluginVersion): string
    {
        if ($markdown === null) {
            return '<div class="alert alert-info">'
                . __s('The user manual is not installed with this copy of the plugin.', 'backupinfo')
                . '</div>';
        }
        $version = self::versionOf($markdown);
        $out     = '';
        if (self::lags($version, $pluginVersion)) {
            $out .= '<div class="alert alert-warning">' . htmlspecialchars(sprintf(
                __('This manual documents version %1$s; the installed plugin is %2$s. Some screens may differ.', 'backupinfo'),
                $version,
                $pluginVersion
            ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        } elseif ($version !== '') {
            $out .= '<p class="text-muted small">' . htmlspecialchars(sprintf(__('Manual for version %s', 'backupinfo'), $version), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        return $out . '<div class="markdown">' . self::render($markdown) . '</div>';
    }
}
