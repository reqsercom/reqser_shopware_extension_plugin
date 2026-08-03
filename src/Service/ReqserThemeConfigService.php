<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\KernelInterface;

/** Dumps every Shopware theme with its configuration and translations. */
class ReqserThemeConfigService
{
    private Connection $connection;
    private KernelInterface $kernel;

    /**
     * @param Connection $connection
     * @param KernelInterface $kernel
     */
    public function __construct(Connection $connection, KernelInterface $kernel)
    {
        $this->connection = $connection;
        $this->kernel = $kernel;
    }

    /**
     * Dump every theme with per-theme translations, annotated with the
     * human locale code (`de-DE`) so consumers don't need a second
     * round-trip to language/locale to identify each translation row.
     *
     * Fail-safe contract (plugin 2.0.25+):
     *   - Each sub-query (locale map, theme list, per-theme translations)
     *     runs in its own try/catch. A failure in one bucket records a
     *     warning and falls back to a sensible empty default; other
     *     buckets still produce data.
     *   - The main `theme` query and `theme_translation` query no longer
     *     carry SQL `ORDER BY` clauses (those tripped MySQL's
     *     `sort_buffer_size` on merchants with default-sized buffers and
     *     large `theme_json` blobs — see laravel.log incident on
     *     radixweb.ch / website 245, 2026-04-27). Sort happens in PHP
     *     after the result set has been streamed out of MySQL.
     *
     * @return array{themes: list<array<string, mixed>>, warnings: list<string>}
     */
    public function dumpThemes(): array
    {
        $warnings = [];

        try {
            $localeMap = $this->fetchLanguageLocaleMap();
        } catch (\Throwable $e) {
            $warnings[] = 'language_locale_map_failed: ' . $e->getMessage();
            $localeMap = [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT
                    LOWER(HEX(id))               AS id,
                    technical_name               AS technicalName,
                    name                         AS name,
                    active                       AS active,
                    LOWER(HEX(parent_theme_id))  AS parentThemeId,
                    base_config                  AS baseConfig,
                    config_values                AS configValues,
                    theme_json                   AS themeJson,
                    created_at                   AS createdAt,
                    updated_at                   AS updatedAt
                 FROM theme'
            );

            // Sort in PHP rather than via SQL `ORDER BY technical_name, id`.
            // MySQL's `sort_buffer_size` is per-session and can be tiny on
            // shared hosting; a sort over the `theme` table fails with
            // `Out of sort memory` because `theme_json` rows are large.
            usort($rows, static function (array $a, array $b): int {
                $cmp = strcmp((string) ($a['technicalName'] ?? ''), (string) ($b['technicalName'] ?? ''));
                return $cmp !== 0 ? $cmp : strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
            });
        } catch (\Throwable $e) {
            $warnings[] = 'theme_query_failed: ' . $e->getMessage();
            return ['themes' => [], 'warnings' => $warnings];
        }

        $result = [];

        foreach ($rows as $row) {
            $themeId = $row['id'];
            $technicalName = $row['technicalName'] !== null ? (string) $row['technicalName'] : null;
            $sourceTheme = $this->readSourceThemeJson($technicalName);

            try {
                $translations = $this->fetchTranslationsForTheme($themeId, $localeMap);
            } catch (\Throwable $e) {
                $warnings[] = "theme_translations_failed[{$themeId}]: " . $e->getMessage();
                $translations = [];
            }

            $result[] = [
                'id'                  => $themeId,
                'technicalName'       => $technicalName,
                'name'                => $row['name'] !== null ? (string) $row['name'] : null,
                'active'              => (bool) $row['active'],
                'parentThemeId'       => ($row['parentThemeId'] === null || $row['parentThemeId'] === '')
                    ? null
                    : $row['parentThemeId'],
                'baseConfig'          => $this->decodeJsonColumn($row['baseConfig']),
                'configValues'        => $this->decodeJsonColumn($row['configValues']),
                'themeJson'           => $this->decodeJsonColumn($row['themeJson']),
                'sourceThemeJson'     => $sourceTheme['sourceThemeJson'],
                'sourceThemeJsonPath' => $sourceTheme['sourceThemeJsonPath'],
                'createdAt'           => $row['createdAt'],
                'updatedAt'           => $row['updatedAt'],
                'translations'        => $translations,
            ];
        }

        return ['themes' => $result, 'warnings' => $warnings];
    }

    /**
     * Resolve a theme's source `Resources/theme.json` file via the kernel
     * bundle registry and return its base64-encoded content + relative
     * path. Returns null/null when the bundle isn't registered, the file
     * doesn't exist, or the file isn't readable. Backward-compatible:
     * keys are always present, values are nullable.
     *
     * @param string|null $technicalName
     * @return array{sourceThemeJson: string|null, sourceThemeJsonPath: string|null}
     */
    private function readSourceThemeJson(string|null $technicalName): array
    {
        $empty = ['sourceThemeJson' => null, 'sourceThemeJsonPath' => null];

        if ($technicalName === null || $technicalName === '') {
            return $empty;
        }

        try {
            $bundle = $this->kernel->getBundle($technicalName);
        } catch (\Throwable) {
            return $empty;
        }

        $sourcePath = $bundle->getPath() . '/Resources/theme.json';
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return $empty;
        }

        $content = @file_get_contents($sourcePath);
        if ($content === false) {
            return $empty;
        }

        $projectDir = rtrim(str_replace('\\', '/', (string) $this->kernel->getProjectDir()), '/');
        $absoluteNorm = str_replace('\\', '/', $sourcePath);
        $relativePath = $projectDir !== '' && str_starts_with($absoluteNorm, $projectDir . '/')
            ? substr($absoluteNorm, strlen($projectDir) + 1)
            : $absoluteNorm;

        return [
            'sourceThemeJson'     => base64_encode($content),
            'sourceThemeJsonPath' => $relativePath,
        ];
    }

    /**
     * Fetch all theme_translation rows for a theme, annotated with the
     * locale code pulled from the language → locale join.
     *
     * SQL `ORDER BY` was removed for the same `sort_buffer_size` reason
     * as `dumpThemes()`; the per-theme translation set is small enough
     * that PHP-side `usort` is effectively free.
     *
     * @param string $themeIdHex
     * @param array $localeMap
     * @return array
     */
    private function fetchTranslationsForTheme(string $themeIdHex, array $localeMap): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                LOWER(HEX(tt.theme_id))    AS theme_id,
                LOWER(HEX(tt.language_id)) AS language_id,
                tt.labels                  AS labels,
                tt.description             AS description,
                tt.help_texts              AS help_texts,
                tt.custom_fields           AS custom_fields,
                tt.created_at              AS created_at,
                tt.updated_at              AS updated_at
             FROM theme_translation tt
             WHERE tt.theme_id = UNHEX(:theme_id)',
            ['theme_id' => $themeIdHex]
        );

        usort($rows, static fn(array $a, array $b): int =>
            strcmp((string) ($a['language_id'] ?? ''), (string) ($b['language_id'] ?? ''))
        );

        $translations = [];

        foreach ($rows as $row) {
            $languageId = $row['language_id'];
            $translations[] = [
                'theme_id'      => $row['theme_id'],
                'language_id'   => $languageId,
                'locale'        => $localeMap[$languageId] ?? null,
                'labels'        => $this->decodeJsonColumn($row['labels']),
                'description'   => $row['description'] !== null ? (string) $row['description'] : null,
                'help_texts'    => $this->decodeJsonColumn($row['help_texts']),
                'custom_fields' => $this->decodeJsonColumn($row['custom_fields']),
                'created_at'    => $row['created_at'],
                'updated_at'    => $row['updated_at'],
            ];
        }

        return $translations;
    }

    /**
     * Build a language_id → locale.code map once per request. The
     * translation dump then looks each row up in O(1) instead of
     * re-joining per row.
     *
     * @return array<string, string>
     */
    private function fetchLanguageLocaleMap(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                LOWER(HEX(l.id)) AS language_id,
                loc.code         AS locale
             FROM language l
             LEFT JOIN locale loc ON loc.id = l.locale_id'
        );

        $map = [];
        foreach ($rows as $row) {
            if (!empty($row['language_id']) && !empty($row['locale'])) {
                $map[$row['language_id']] = (string) $row['locale'];
            }
        }

        return $map;
    }

    /**
     * Decode a DB JSON column. Returns null for empty input, the decoded
     * structure when the payload is valid JSON, or the raw string when
     * it is non-empty but not JSON (defensive — theme.base_config is
     * always JSON in practice, but we never want a decode failure to
     * swallow data).
     *
     * @param mixed $value
     * @return array|string|null
     */
    private function decodeJsonColumn(mixed $value): array|string|null
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }
}
