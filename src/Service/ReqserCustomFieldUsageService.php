<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Finder\Finder;
use Twig\Loader\FilesystemLoader;

/**
 * Service for analyzing which custom fields are actually referenced anywhere
 * the storefront might render them.
 *
 * ## Scope of detection (two surfaces)
 *
 * 1. **Filesystem `.html.twig` templates** (`scanTwigFiles()`) — uses Shopware's
 *    native Twig FilesystemLoader to discover registered template directories
 *    (core, plugins, apps, themes) and Symfony Finder to scan them for static
 *    `customFields.X` references.
 *
 * 2. **`cms_slot_translation.config` JSON column** (`scanCmsSlotConfigs()`) —
 *    introduced in plugin 2.0.26. Catches two patterns the file scanner cannot
 *    see because the Twig source lives in the database, not on disk:
 *
 *    a. **Shopware native "mapped" elements** — `{source: "mapped",
 *       value: "category.translated.customFields.X"}` inside slot config.
 *       Shopware resolves the entity property path at render time.
 *
 *    b. **Dynamic Twig stored as a string** — `{__template: {value: "<h2>{{
 *       category.customFields.X }}</h2>"}}` and similar shapes used by
 *       AkuCmsFactory and other plugins that pass templates to
 *       `template_from_string()` at render time.
 *
 *    Reproducer: schlanser.ch (Reqser website 254), CMS slot
 *    `019cd29eb35273dbb4181867883f791c`, custom field
 *    `custom_category_arboro_cat_seo_text` — invisible to the filesystem
 *    scanner but rendered live on the storefront.
 *
 * Cross-references found keys against the `custom_field` table to return only
 * actual registered custom fields, including the entity they belong to and
 * how they are accessed (translated vs direct).
 *
 * ## Known remaining limitations
 *
 * - Headless API consumers (Store API → custom storefront / app) are not
 *   detected.
 * - Flow Builder action bodies and rule-engine references to `customFields`
 *   are not scanned.
 * - References inside `theme.json` config blobs (not slot configs) are not
 *   scanned.
 *
 * See `docs/integrations/shopware_plugin/CUSTOM-FIELD-USAGE-SCANNER.md` in the
 * Reqser app repository for the authoritative writeup.
 */
class ReqserCustomFieldUsageService
{
    /**
     * Hard cap on the number of cms_slot_translation rows we inspect in a
     * single call. Even very large shops have at most a few thousand slots
     * across all sales channels and languages combined; this is a safety
     * belt against pathological cases (and prevents the route from melting
     * down if the prefilter ever stops working). Hitting the cap surfaces a
     * `_warnings` entry in the response (handled at the controller layer).
     */
    private const CMS_SLOT_SCAN_LIMIT = 10000;

    private Connection $connection;
    private FilesystemLoader $loader;

    /**
     * @param Connection $connection
     * @param FilesystemLoader $loader
     */
    public function __construct(Connection $connection, FilesystemLoader $loader)
    {
        $this->connection = $connection;
        $this->loader = $loader;
    }

    /**
     * Analyze which registered custom fields are referenced in Twig template
     * files and in `cms_slot_translation.config` JSON.
     *
     * @return array{
     *     fields: array<int, array{
     *         name: string,
     *         type: string,
     *         entities: array<string>,
     *         twigFiles: array<int, array{file: string, accessPatterns: array<string>, references: array<string>}>,
     *         cmsSlotReferences: array<int, array{slotId: string, slotType: string, source: string, accessPatterns: array<string>, references: array<string>}>
     *     }>,
     *     totalCustomFields: int,
     *     displayedCustomFields: int
     * }
     */
    public function getCustomFieldTwigUsage(): array
    {
        $registeredFields = $this->getRegisteredCustomFields();

        $templateDirs = $this->getTemplateDirs();
        $twigUsageMap = $this->scanTwigFiles($templateDirs);

        $cmsSlotUsageMap = $this->scanCmsSlotConfigs();

        $fields = [];
        foreach ($registeredFields as $fieldName => $fieldInfo) {
            $hasTwig = isset($twigUsageMap[$fieldName]);
            $hasCmsSlot = isset($cmsSlotUsageMap[$fieldName]);

            if (!$hasTwig && !$hasCmsSlot) {
                continue;
            }

            $twigFiles = [];
            if ($hasTwig) {
                foreach ($twigUsageMap[$fieldName] as $fileName => $fileData) {
                    $twigFiles[] = [
                        'file' => $fileName,
                        'accessPatterns' => array_keys($fileData['accessPatterns']),
                        'references' => array_keys($fileData['references']),
                    ];
                }
            }

            $cmsSlotReferences = [];
            if ($hasCmsSlot) {
                foreach ($cmsSlotUsageMap[$fieldName] as $slotKey => $slotData) {
                    $cmsSlotReferences[] = [
                        'slotId' => $slotData['slotId'],
                        'slotType' => $slotData['slotType'],
                        'source' => $slotData['source'],
                        'accessPatterns' => array_keys($slotData['accessPatterns']),
                        'references' => array_keys($slotData['references']),
                    ];
                }
            }

            $fields[] = [
                'name' => $fieldName,
                'type' => $fieldInfo['type'],
                'entities' => $fieldInfo['entities'],
                'twigFiles' => $twigFiles,
                'cmsSlotReferences' => $cmsSlotReferences,
            ];
        }

        return [
            'fields' => $fields,
            'totalCustomFields' => count($registeredFields),
            'displayedCustomFields' => count($fields),
        ];
    }

    /**
     * Get all active custom fields from the database with their type and assigned entities.
     * Joins through custom_field_set_relation to resolve which entities each field belongs to.
     *
     * @return array<string, array{type: string, entities: array<string>}>
     */
    private function getRegisteredCustomFields(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT cf.`name`, cf.`type`, cfsr.`entity_name`
             FROM `custom_field` cf
             LEFT JOIN `custom_field_set_relation` cfsr ON cf.`set_id` = cfsr.`set_id`
             WHERE cf.`active` = 1'
        );

        $fields = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            if (!isset($fields[$name])) {
                $fields[$name] = [
                    'type' => $row['type'],
                    'entities' => [],
                ];
            }
            if ($row['entity_name'] !== null && !in_array($row['entity_name'], $fields[$name]['entities'], true)) {
                $fields[$name]['entities'][] = $row['entity_name'];
            }
        }

        return $fields;
    }

    /**
     * Collect all unique template directories from Shopware's Twig FilesystemLoader.
     * This automatically includes core Storefront, all active plugins, apps, and themes.
     *
     * @return array<string>
     */
    private function getTemplateDirs(): array
    {
        $dirs = [];

        $namespaces = $this->loader->getNamespaces();
        foreach ($namespaces as $namespace) {
            $paths = $this->loader->getPaths($namespace);
            foreach ($paths as $path) {
                if (is_dir($path)) {
                    $dirs[$path] = true;
                }
            }
        }

        return array_keys($dirs);
    }

    /**
     * Scan all .html.twig files in the given directories for customFields references.
     *
     * Detects patterns and categorizes them as "translated" or "direct":
     *   - .translated.customFields.KEY              (translated dot access)
     *   - .translated.customFields['KEY']           (translated bracket access)
     *   - .customFields.KEY                         (direct dot access)
     *   - .customFields['KEY'] / customFields["KEY"] (direct bracket access)
     *
     * @param array $dirs
     * @return array
     */
    private function scanTwigFiles(array $dirs): array
    {
        if (empty($dirs)) {
            return [];
        }

        $usageMap = [];

        $finder = new Finder();
        $finder->files()->name('*.html.twig')->in($dirs);

        foreach ($finder as $file) {
            $content = $file->getContents();
            $fileName = $file->getRelativePathname();

            $keyData = $this->extractCustomFieldKeys($content);

            foreach ($keyData as $key => $data) {
                if (!isset($usageMap[$key][$fileName])) {
                    $usageMap[$key][$fileName] = [
                        'accessPatterns' => [],
                        'references' => [],
                    ];
                }
                foreach ($data['accessPatterns'] as $pattern) {
                    $usageMap[$key][$fileName]['accessPatterns'][$pattern] = true;
                }
                foreach ($data['references'] as $ref) {
                    $usageMap[$key][$fileName]['references'][$ref] = true;
                }
            }
        }

        return $usageMap;
    }

    /**
     * Scan `cms_slot_translation.config` JSON for customFields references that
     * the filesystem scanner cannot see.
     *
     * Two patterns are recognized:
     *
     * 1. **Mapped** — when a config entry has `source: "mapped"`, the `value`
     *    is an entity property path like `category.translated.customFields.X`.
     *    Tagged as `cms_slot_mapped`.
     *
     * 2. **Static / dynamic Twig string** — any other string containing
     *    `customFields` is treated as Twig source (covers AkuCmsFactory's
     *    `__template` pattern and any plugin that stores Twig in slot config).
     *    Tagged as `cms_slot_template`.
     *
     * The walker is recursive and source-context-aware, so nested arrays
     * (e.g. slider/list element configs) are handled transparently.
     *
     * Performance: SQL prefilter `config LIKE '%customFields%'` keeps the
     * working set small. A hard `LIMIT` (CMS_SLOT_SCAN_LIMIT) is the safety
     * belt for pathological shops.
     *
     * @return array<string, array<string, array{
     *     slotId: string,
     *     slotType: string,
     *     source: string,
     *     accessPatterns: array<string, bool>,
     *     references: array<string, bool>
     * }>>
     */
    private function scanCmsSlotConfigs(): array
    {
        $usageMap = [];

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(cs.id)) AS slot_id, cs.type AS slot_type, cst.config
                 FROM cms_slot_translation cst
                 INNER JOIN cms_slot cs ON cs.id = cst.cms_slot_id AND cs.version_id = cst.cms_slot_version_id
                 WHERE cst.config IS NOT NULL AND cst.config LIKE :needle
                 LIMIT :limit',
                ['needle' => '%customFields%', 'limit' => self::CMS_SLOT_SCAN_LIMIT],
                ['needle' => \PDO::PARAM_STR, 'limit' => \PDO::PARAM_INT]
            );
        } catch (\Throwable $e) {
            // Fail-soft: a SQL error here must not break the whole route.
            // The filesystem scan still produces useful output. The empty
            // map causes no false positives.
            return [];
        }

        foreach ($rows as $row) {
            $configString = $row['config'];
            if (!is_string($configString) || $configString === '') {
                continue;
            }

            $decoded = json_decode($configString, true);
            if (!is_array($decoded)) {
                continue;
            }

            $slotId = (string)($row['slot_id'] ?? '');
            $slotType = (string)($row['slot_type'] ?? '');

            $emit = function (string $key, string $sourceKind, string $accessPattern, string $reference) use (&$usageMap, $slotId, $slotType): void {
                $bucket = $slotId . ':' . $sourceKind;
                if (!isset($usageMap[$key][$bucket])) {
                    $usageMap[$key][$bucket] = [
                        'slotId' => $slotId,
                        'slotType' => $slotType,
                        'source' => $sourceKind,
                        'accessPatterns' => [],
                        'references' => [],
                    ];
                }
                $usageMap[$key][$bucket]['accessPatterns'][$accessPattern] = true;
                $usageMap[$key][$bucket]['references'][$reference] = true;
            };

            $this->walkConfigForCustomFields($decoded, null, $emit);
        }

        return $usageMap;
    }

    /**
     * Recursive walker over a decoded `cms_slot_translation.config` JSON tree.
     *
     * Behaviour:
     * - When the node is an associative array carrying `source` + `value`,
     *   recurse into `value` while propagating `source` as the context.
     * - When the node is a string, apply the standard customFields regexes.
     *   The emitted access pattern is `cms_slot_mapped` if the surrounding
     *   `source === 'mapped'`, otherwise `cms_slot_template`.
     * - When the node is an array (numeric or associative without
     *   source/value), recurse into every element preserving the parent
     *   context. This handles slider/list element configs whose `value` is
     *   an array of nested `{source, value}` objects.
     *
     * @param mixed         $node
     * @param string|null   $sourceContext  The `source` value of the closest enclosing object
     * @param callable      $emit           function(string $key, string $sourceKind, string $accessPattern, string $reference): void
     */
    private function walkConfigForCustomFields($node, $sourceContext, callable $emit): void
    {
        if (is_string($node)) {
            if (strpos($node, 'customFields') === false) {
                return;
            }
            $sourceKind = ($sourceContext === 'mapped') ? 'cms_slot_mapped' : 'cms_slot_template';
            $keyData = $this->extractCustomFieldKeys($node);
            foreach ($keyData as $key => $data) {
                foreach ($data['accessPatterns'] as $pattern) {
                    foreach ($data['references'] as $reference) {
                        $emit($key, $sourceKind, $pattern, $reference);
                    }
                }
            }
            return;
        }

        if (!is_array($node)) {
            return;
        }

        // Associative {source, value} pair: descend with new context
        if (array_key_exists('source', $node) && array_key_exists('value', $node)) {
            $newContext = is_string($node['source']) ? $node['source'] : null;
            $this->walkConfigForCustomFields($node['value'], $newContext, $emit);
            // Some configs carry sibling keys (e.g. btnType, verticalAlign);
            // those are handled by the next foreach below — but for
            // {source, value} pairs we have already drilled into value, so
            // skip to avoid double-counting.
            foreach ($node as $key => $child) {
                if ($key === 'source' || $key === 'value') {
                    continue;
                }
                $this->walkConfigForCustomFields($child, $sourceContext, $emit);
            }
            return;
        }

        foreach ($node as $child) {
            $this->walkConfigForCustomFields($child, $sourceContext, $emit);
        }
    }

    /**
     * Extract custom field key names, their access patterns, and the full Twig expressions.
     *
     * Returns a map of fieldKey => ['accessPatterns' => [...], 'references' => [...]].
     *
     * @param string $content
     * @return array
     */
    private function extractCustomFieldKeys(string $content): array
    {
        $keyData = [];

        // Dot access: entity.customFields.KEY or entity.translated.customFields.KEY
        if (preg_match_all('/(\w+(?:\.\w+)*)\.customFields\.(\w+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[2];
                $prefix = $match[1];
                $isTranslated = str_ends_with($prefix, '.translated') || $prefix === 'translated';

                $keyData[$key]['accessPatterns'][$isTranslated ? 'translated' : 'direct'] = true;
                $keyData[$key]['references'][$match[0]] = true;
            }
        }

        // Bracket access with entity prefix: entity.customFields['KEY'] or entity.translated.customFields['KEY']
        if (preg_match_all('/(\w+(?:\.\w+)*)\.customFields\[[\'"](\w+)[\'"]\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[2];
                $prefix = $match[1];
                $isTranslated = str_ends_with($prefix, '.translated') || $prefix === 'translated';

                $keyData[$key]['accessPatterns'][$isTranslated ? 'translated' : 'direct'] = true;
                $keyData[$key]['references'][$match[0]] = true;
            }
        }

        // Standalone bracket access without entity prefix (e.g. customFields['KEY'] after a pipe or filter)
        if (preg_match_all('/(?<![\w.])customFields\[[\'"](\w+)[\'"]\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $keyData[$key]['accessPatterns']['direct'] = true;
                $keyData[$key]['references'][$match[0]] = true;
            }
        }

        // Convert to clean arrays
        $result = [];
        foreach ($keyData as $key => $data) {
            $result[$key] = [
                'accessPatterns' => array_keys($data['accessPatterns']),
                'references' => array_keys($data['references']),
            ];
        }

        return $result;
    }
}
