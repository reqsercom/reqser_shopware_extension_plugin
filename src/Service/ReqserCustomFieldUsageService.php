<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Finder\Finder;
use Twig\Loader\FilesystemLoader;

/**
 * Reports the active custom fields that are referenced from at least one Twig template
 * or one CMS slot config.
 */
class ReqserCustomFieldUsageService
{
    private const PATTERN_TRANSLATED = 'translated';
    private const PATTERN_PAYLOAD = 'payload';
    private const PATTERN_DIRECT = 'direct';
    private const PATTERN_DYNAMIC = 'dynamic';

    private Connection $connection;
    private FilesystemLoader $loader;
    private ReqserJsonFieldDetectionService $jsonFieldDetectionService;
    private ReqserSystemConfigValueResolver $configValueResolver;

    public function __construct(
        Connection $connection,
        FilesystemLoader $loader,
        ReqserJsonFieldDetectionService $jsonFieldDetectionService,
        ReqserSystemConfigValueResolver $configValueResolver
    ) {
        $this->connection = $connection;
        $this->loader = $loader;
        $this->jsonFieldDetectionService = $jsonFieldDetectionService;
        $this->configValueResolver = $configValueResolver;
    }

    /**
     * Analyze which registered custom fields are referenced in Twig templates AND/OR CMS slot configs.
     *
     * @return array{
     *     fields: array<int, array{
     *         name: string,
     *         type: string,
     *         entities: array<string>,
     *         twigFiles: array<int, array{file: string, accessPatterns: array<string>, references: array<string>}>,
     *         cmsSlots: array<int, array{table: string, column: string, entityId: string, languageId: string, paths: array<string>}>
     *     }>,
     *     totalCustomFields: int,
     *     displayedCustomFields: int
     * }
     */
    public function getCustomFieldUsage(): array
    {
        $registeredFields = $this->getRegisteredCustomFields();
        $templateDirs = $this->getTemplateDirs();
        $twigUsageMap = $this->scanTwigFiles($templateDirs, array_keys($registeredFields));
        $cmsUsageMap = $this->scanCmsTablesForCustomFieldReferences();

        $fields = [];
        foreach ($registeredFields as $fieldName => $fieldInfo) {
            $hasTwig = isset($twigUsageMap[$fieldName]);
            $hasCms = isset($cmsUsageMap[$fieldName]);

            if (!$hasTwig && !$hasCms) {
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

            $cmsSlots = [];
            if ($hasCms) {
                foreach ($cmsUsageMap[$fieldName] as $hit) {
                    $cmsSlots[] = [
                        'table' => $hit['table'],
                        'column' => $hit['column'],
                        'entityId' => $hit['entityId'],
                        'languageId' => $hit['languageId'],
                        'paths' => $hit['paths'],
                    ];
                }
            }

            $fields[] = [
                'name' => $fieldName,
                'type' => $fieldInfo['type'],
                'entities' => $fieldInfo['entities'],
                'twigFiles' => $twigFiles,
                'cmsSlots' => $cmsSlots,
            ];
        }

        return [
            'fields' => $fields,
            'totalCustomFields' => count($registeredFields),
            'displayedCustomFields' => count($fields),
        ];
    }

    /**
     * Get all active custom fields with their type and assigned entities.
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
     * Scan all .html.twig files for customFields references, classifying each reference as
     * "translated", "payload" or "direct" access.
     *
     * @param array<string> $dirs
     * @param array<string> $registeredFieldNames
     * @return array<string, array<string, array{accessPatterns: array<string, true>, references: array<string, true>}>>
     */
    private function scanTwigFiles(array $dirs, array $registeredFieldNames): array
    {
        if (empty($dirs)) {
            return [];
        }

        $registeredLookup = array_fill_keys($registeredFieldNames, true);
        $usageMap = [];

        foreach ($this->collectTwigFilesByPhysicalFile($dirs) as $entry) {
            $content = $entry['file']->getContents();
            $fileName = $entry['name'];

            $this->mergeKeyDataIntoUsageMap(
                $this->extractCustomFieldKeysFromTwig($content),
                $fileName,
                $usageMap
            );

            // Dynamic keys cost six extra regex passes plus a config lookup, so skip every
            // file that cannot possibly hold both halves of the indirection.
            if (str_contains($content, 'customFields') && str_contains($content, 'config(')) {
                $this->mergeKeyDataIntoUsageMap(
                    $this->extractDynamicCustomFieldKeysFromTwig($content, $registeredLookup),
                    $fileName,
                    $usageMap
                );
            }
        }

        return $usageMap;
    }

    /**
     * Union one file's extracted key data into the cross-file usage map.
     *
     * @param array<string, array{accessPatterns: array<string>, references: array<string>}> $keyData
     * @param array<string, array<string, array{accessPatterns: array<string, true>, references: array<string, true>}>> $usageMap
     */
    private function mergeKeyDataIntoUsageMap(array $keyData, string $fileName, array &$usageMap): void
    {
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

    /**
     * Collect every .html.twig file below the given directories, one entry per physical file.
     *
     * @param array<string> $dirs
     * @return list<array{name: string, file: \Symfony\Component\Finder\SplFileInfo}>
     */
    private function collectTwigFilesByPhysicalFile(array $dirs): array
    {
        $finder = new Finder();
        $finder->files()->name('*.html.twig')->in($dirs);

        // Shopware registers overlapping loader roots per bundle (Resources and
        // Resources/views), so one template is reachable under two relative names.
        // Keying on the resolved path collapses those to a single entry.
        $byPhysicalFile = [];

        foreach ($finder as $file) {
            $physicalPath = $file->getRealPath();
            if ($physicalPath === false) {
                $physicalPath = $file->getPathname();
            }

            $name = str_replace('\\', '/', $file->getRelativePathname());

            if (
                !isset($byPhysicalFile[$physicalPath])
                || $this->isShorterName($name, $byPhysicalFile[$physicalPath]['name'])
            ) {
                $byPhysicalFile[$physicalPath] = ['name' => $name, 'file' => $file];
            }
        }

        return array_values($byPhysicalFile);
    }

    /**
     * Compare two relative names of the same file, shortest first and lexicographic on a tie.
     *
     * @param string $candidate
     * @param string $current
     * @return bool
     */
    private function isShorterName(string $candidate, string $current): bool
    {
        if (strlen($candidate) !== strlen($current)) {
            return strlen($candidate) < strlen($current);
        }

        return strcmp($candidate, $current) < 0;
    }

    /**
     * Extract custom field key names, access patterns ("translated" / "payload" / "direct"),
     * and full Twig expressions from template source.
     *
     * @return array<string, array{accessPatterns: array<string>, references: array<string>}>
     */
    private function extractCustomFieldKeysFromTwig(string $content): array
    {
        $keyData = [];

        // Dot access: entity.customFields.KEY, entity.translated.customFields.KEY
        // or lineItem.payload.customFields.KEY
        if (preg_match_all('/(\w+(?:\.\w+)*)\.customFields\.(\w+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[2];

                $keyData[$key]['accessPatterns'][$this->classifyAccessPrefix($match[1])] = true;
                $keyData[$key]['references'][$match[0]] = true;
            }
        }

        // Bracket access with entity prefix: entity.customFields['KEY'], entity.translated.customFields['KEY']
        // or lineItem.payload.customFields['KEY']
        if (preg_match_all('/(\w+(?:\.\w+)*)\.customFields\[[\'"](\w+)[\'"]\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[2];

                $keyData[$key]['accessPatterns'][$this->classifyAccessPrefix($match[1])] = true;
                $keyData[$key]['references'][$match[0]] = true;
            }
        }

        // Standalone bracket access without entity prefix (e.g. customFields['KEY'] after a pipe or filter)
        if (preg_match_all('/(?<![\w.])customFields\[[\'"](\w+)[\'"]\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $keyData[$key]['accessPatterns'][self::PATTERN_DIRECT] = true;
                $keyData[$key]['references'][$match[0]] = true;
            }
        }

        // Bracket access on customFields itself: entity["customFields"]["KEY"],
        // entity.translated["customFields"]["KEY"], entity["translated"]["customFields"]["KEY"],
        // or the same shapes with a payload marker
        if (preg_match_all('/(\.translated|\[[\'"]translated[\'"]\]|\.payload|\[[\'"]payload[\'"]\])?\s*\[[\'"]customFields[\'"]\]\s*\[[\'"](\w+)[\'"]\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[2];

                $keyData[$key]['accessPatterns'][$this->classifyBracketMarker($match[1] ?? '')] = true;
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

    /**
     * Extract custom field keys that a template reads through a runtime index bound to a
     * system-config value, e.g. {% set f = config('Theme.config.subTitle') %}{{ p.customFields[f] }}.
     *
     * @param array<string, true> $registeredLookup
     * @return array<string, array{accessPatterns: array<string>, references: array<string>}>
     */
    private function extractDynamicCustomFieldKeysFromTwig(string $content, array $registeredLookup): array
    {
        $keyData = [];

        $configBindings = $this->collectConfigBindings($content);
        $containerAliases = $this->collectContainerAliases($content);

        if (!empty($configBindings) && !empty($containerAliases)) {
            // Aliased container indexed by a bound variable: {{ alias[field] }}
            if (preg_match_all('/(\w+)\s*\[\s*(\w+)\s*\]/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $alias = $match[1];
                    $variable = $match[2];

                    if (!isset($containerAliases[$alias], $configBindings[$variable])) {
                        continue;
                    }

                    $this->recordDynamicHit(
                        $configBindings[$variable],
                        $containerAliases[$alias]['pattern'],
                        [
                            $containerAliases[$alias]['reference'],
                            $configBindings[$variable]['reference'],
                            $match[0],
                        ],
                        $registeredLookup,
                        $keyData
                    );
                }
            }
        }

        if (!empty($configBindings)) {
            // Direct container indexed by a bound variable: {{ entity.customFields[field] }}
            if (preg_match_all('/([\w.]+)\.customFields\s*\[\s*(\w+)\s*\]/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $variable = $match[2];
                    if (!isset($configBindings[$variable])) {
                        continue;
                    }

                    $this->recordDynamicHit(
                        $configBindings[$variable],
                        $this->classifyAccessPrefix($match[1]),
                        [$configBindings[$variable]['reference'], $match[0]],
                        $registeredLookup,
                        $keyData
                    );
                }
            }

            // Bracket-string container twin: {{ entity["customFields"][field] }}
            if (preg_match_all('/(\.translated|\[[\'"]translated[\'"]\]|\.payload|\[[\'"]payload[\'"]\])?\s*\[[\'"]customFields[\'"]\]\s*\[\s*(\w+)\s*\]/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $variable = $match[2];
                    if (!isset($configBindings[$variable])) {
                        continue;
                    }

                    $this->recordDynamicHit(
                        $configBindings[$variable],
                        $this->classifyBracketMarker($match[1] ?? ''),
                        [$configBindings[$variable]['reference'], $match[0]],
                        $registeredLookup,
                        $keyData
                    );
                }
            }
        }

        // Inline config() in the index: {{ entity.translated.customFields[config('Key')] }}
        if (preg_match_all('/([\w.]+)\.customFields\s*\[\s*config\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*\]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->recordDynamicHit(
                    ['configKey' => $match[2], 'default' => null, 'reference' => $match[0]],
                    $this->classifyAccessPrefix($match[1]),
                    [$match[0]],
                    $registeredLookup,
                    $keyData
                );
            }
        }

        $result = [];
        foreach ($keyData as $key => $data) {
            $result[$key] = [
                'accessPatterns' => array_keys($data['accessPatterns']),
                'references' => array_keys($data['references']),
            ];
        }

        return $result;
    }

    /**
     * Collect {% set VAR = config('KEY') %} bindings, including an optional literal
     * |default('...') that Shopware falls back to when the setting is unset.
     *
     * @return array<string, array{configKey: string, default: string|null, reference: string}>
     */
    private function collectConfigBindings(string $content): array
    {
        $bindings = [];

        if (!preg_match_all(
            '/\{%-?\s*set\s+(\w+)\s*=\s*config\(\s*[\'"]([^\'"]+)[\'"]\s*\)(?:\s*\|\s*default\(\s*[\'"]([^\'"]+)[\'"]\s*\))?/',
            $content,
            $matches,
            PREG_SET_ORDER
        )) {
            return $bindings;
        }

        foreach ($matches as $match) {
            $default = $match[3] ?? '';

            $bindings[$match[1]] = [
                'configKey' => $match[2],
                'default' => $default !== '' ? $default : null,
                'reference' => trim($match[0]),
            ];
        }

        return $bindings;
    }

    /**
     * Collect {% set ALIAS = entity.customFields %} bindings so a later alias[field] read
     * can be attributed to the container it actually points at.
     *
     * @return array<string, array{pattern: string, reference: string}>
     */
    private function collectContainerAliases(string $content): array
    {
        $aliases = [];

        if (!preg_match_all('/\{%-?\s*set\s+(\w+)\s*=\s*([\w.]+)\.customFields\s*-?%\}/', $content, $matches, PREG_SET_ORDER)) {
            return $aliases;
        }

        foreach ($matches as $match) {
            $aliases[$match[1]] = [
                'pattern' => $this->classifyAccessPrefix($match[2]),
                'reference' => trim($match[0]),
            ];
        }

        return $aliases;
    }

    /**
     * Resolve one config binding to registered custom field names and record the hit.
     *
     * Every dynamic pass funnels through here so the registered-field guard and the
     * "dynamic" marker are applied in exactly one place.
     *
     * @param array{configKey: string, default: string|null, reference: string} $binding
     * @param array<string> $references
     * @param array<string, true> $registeredLookup
     * @param array<string, array{accessPatterns: array<string, true>, references: array<string, true>}> $keyData
     */
    private function recordDynamicHit(
        array $binding,
        string $pattern,
        array $references,
        array $registeredLookup,
        array &$keyData
    ): void {
        $candidates = $this->configValueResolver->resolve($binding['configKey']);

        if (empty($candidates) && $binding['default'] !== null) {
            $candidates = [$binding['default']];
        }

        foreach ($candidates as $candidate) {
            if (!isset($registeredLookup[$candidate])) {
                continue;
            }

            $keyData[$candidate]['accessPatterns'][$pattern] = true;
            $keyData[$candidate]['accessPatterns'][self::PATTERN_DYNAMIC] = true;

            foreach ($references as $reference) {
                $keyData[$candidate]['references'][$reference] = true;
            }
        }
    }

    /**
     * Classify the expression part that precedes ".customFields".
     *
     * A payload prefix is fallback-safe: Shopware fills the cart line item payload from
     * getTranslation('customFields'), and the order line item payload is a persisted copy
     * of that already language-resolved value.
     */
    private function classifyAccessPrefix(string $prefix): string
    {
        if ($prefix === 'translated' || str_ends_with($prefix, '.translated')) {
            return self::PATTERN_TRANSLATED;
        }

        if ($prefix === 'payload' || str_ends_with($prefix, '.payload')) {
            return self::PATTERN_PAYLOAD;
        }

        return self::PATTERN_DIRECT;
    }

    /**
     * Classify the optional marker captured before a bracketed ["customFields"] access.
     */
    private function classifyBracketMarker(string $marker): string
    {
        if ($marker === '') {
            return self::PATTERN_DIRECT;
        }

        return str_contains($marker, 'payload') ? self::PATTERN_PAYLOAD : self::PATTERN_TRANSLATED;
    }

    /**
     * Scan slot-config-bearing translation columns for customFields references in CMS config.
     *
     * Matches both source=mapped paths and source=static Twig expressions such as
     * {{ category.customFields.category_text }}.
     *
     * @return array<string, array<int, array{table: string, column: string, entityId: string, languageId: string, paths: array<string>}>>
     */
    private function scanCmsTablesForCustomFieldReferences(): array
    {
        $byField = [];

        foreach ($this->jsonFieldDetectionService->listSlotConfigBearingTranslationColumns() as $tableSpec) {
            $table = $tableSpec['table'];
            $column = $tableSpec['column'];
            $idColumn = $tableSpec['idColumn'];

            // Pre-filter at SQL level: rows must reference customFields via mapped or static CMS config.
            $sql = "SELECT LOWER(HEX(`{$idColumn}`)) AS entity_id, "
                . "LOWER(HEX(`language_id`)) AS language_id, "
                . "`{$column}` AS config_json "
                . "FROM `{$table}` "
                . "WHERE `{$column}` IS NOT NULL "
                . "AND `{$column}` LIKE '%customFields%' "
                . "AND (`{$column}` LIKE '%\"mapped\"%' OR `{$column}` LIKE '%\"static\"%')";

            try {
                $rows = $this->connection->fetchAllAssociative($sql);
            } catch (\Throwable $e) {
                // Definition exists but DB schema lags (e.g. plugin migration not yet run).
                // Skip silently; the route should not 500 on transient install state.
                continue;
            }

            foreach ($rows as $row) {
                $entityId = (string) ($row['entity_id'] ?? '');
                $languageId = (string) ($row['language_id'] ?? '');
                $configJson = (string) $row['config_json'];

                $hits = $this->extractCustomFieldKeysFromConfigJson($configJson);
                if (empty($hits)) {
                    continue;
                }

                $rowKey = $table . '|' . $column . '|' . $entityId . '|' . $languageId;

                foreach ($hits as $fieldKey => $values) {
                    if (!isset($byField[$fieldKey][$rowKey])) {
                        $byField[$fieldKey][$rowKey] = [
                            'table' => $table,
                            'column' => $column,
                            'entityId' => $entityId,
                            'languageId' => $languageId,
                            'paths' => [],
                        ];
                    }
                    foreach ($values as $value) {
                        if (!in_array($value, $byField[$fieldKey][$rowKey]['paths'], true)) {
                            $byField[$fieldKey][$rowKey]['paths'][] = $value;
                        }
                    }
                }
            }
        }

        // Reindex inner array (drop rowKey assoc keys → list)
        $usageMap = [];
        foreach ($byField as $fieldKey => $rowsMap) {
            $usageMap[$fieldKey] = array_values($rowsMap);
        }

        return $usageMap;
    }

    /**
     * Extract customFields.<key> / customFields['<key>'] paths from CMS slot config JSON.
     *
     * @return array<string, list<string>>  fieldKey => list of full path strings
     */
    private function extractCustomFieldKeysFromConfigJson(string $jsonContent): array
    {
        if ($jsonContent === '') {
            return [];
        }

        $decoded = json_decode($jsonContent, true);
        if (!is_array($decoded)) {
            return [];
        }

        $found = [];
        $this->walkConfigForCustomFieldReferences($decoded, $found);

        return $found;
    }

    /**
     * Recursive walker — populates $found with fieldKey => list<path>.
     * A CMS config leaf is any associative array with source=mapped or source=static
     * plus a string value; customFields paths are extracted and the leaf is not descended further.
     *
     * @param mixed $node
     * @param array<string, list<string>> $found
     */
    private function walkConfigForCustomFieldReferences($node, array &$found): void
    {
        if (!is_array($node)) {
            return;
        }

        if (
            isset($node['source'], $node['value'])
            && is_string($node['source'])
            && is_string($node['value'])
            && ($node['source'] === 'mapped' || $node['source'] === 'static')
        ) {
            $this->appendCustomFieldPathsFromString($node['value'], $found);

            return;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->walkConfigForCustomFieldReferences($child, $found);
            }
        }
    }

    /**
     * Extract custom field keys and their dotted/bracket paths from a mapped path or static Twig value.
     *
     * @param array<string, list<string>> $found
     */
    private function appendCustomFieldPathsFromString(string $value, array &$found): void
    {
        if (!str_contains($value, 'customFields')) {
            return;
        }

        // Dot access: entity.customFields.KEY or entity.translated.customFields.KEY
        if (preg_match_all('/(\w+(?:\.\w+)*)\.customFields\.(\w+)/', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[2];
                $path = $match[0];

                if (!isset($found[$key])) {
                    $found[$key] = [];
                }
                if (!in_array($path, $found[$key], true)) {
                    $found[$key][] = $path;
                }
            }
        }

        // Bracket access: entity.customFields['KEY'] or entity.translated.customFields['KEY']
        if (preg_match_all('/(\w+(?:\.\w+)*)\.customFields\[[\'"](\w+)[\'"]\]/', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[2];
                $path = $match[0];

                if (!isset($found[$key])) {
                    $found[$key] = [];
                }
                if (!in_array($path, $found[$key], true)) {
                    $found[$key][] = $path;
                }
            }
        }

        // Bracket access on customFields itself: entity["customFields"]["KEY"] etc.
        if (preg_match_all('/(\.translated|\[[\'"]translated[\'"]\])?\s*\[[\'"]customFields[\'"]\]\s*\[[\'"](\w+)[\'"]\]/', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[2];
                $path = $match[0];

                if (!isset($found[$key])) {
                    $found[$key] = [];
                }
                if (!in_array($path, $found[$key], true)) {
                    $found[$key][] = $path;
                }
            }
        }
    }
}
