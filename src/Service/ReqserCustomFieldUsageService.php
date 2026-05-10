<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Cms\DataAbstractionLayer\Field\SlotConfigField;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityTranslationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Symfony\Component\Finder\Finder;
use Twig\Loader\FilesystemLoader;

/**
 * Reports the active custom fields that are referenced from at least one Twig template
 * or one CMS slot config.
 */
class ReqserCustomFieldUsageService
{
    private Connection $connection;
    private FilesystemLoader $loader;
    private DefinitionInstanceRegistry $definitionRegistry;

    public function __construct(
        Connection $connection,
        FilesystemLoader $loader,
        DefinitionInstanceRegistry $definitionRegistry
    ) {
        $this->connection = $connection;
        $this->loader = $loader;
        $this->definitionRegistry = $definitionRegistry;
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
        $twigUsageMap = $this->scanTwigFiles($templateDirs);
        $cmsUsageMap = $this->scanCmsTablesForMappedCustomFields();

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
     * Scan all .html.twig files for customFields references, classifying each as
     * "translated" or "direct" access.
     *
     * @param array<string> $dirs
     * @return array<string, array<string, array{accessPatterns: array<string, true>, references: array<string, true>}>>
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

            $keyData = $this->extractCustomFieldKeysFromTwig($content);

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
     * Extract custom field key names, access patterns ("translated" / "direct"),
     * and full Twig expressions from template source.
     *
     * @return array<string, array{accessPatterns: array<string>, references: array<string>}>
     */
    private function extractCustomFieldKeysFromTwig(string $content): array
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

    /**
     * Discover slot-config-bearing translation tables dynamically from the DAL.
     * A column is included when its translation definition declares it as a SlotConfigField,
     * or as a JsonField whose storage name is `slot_config`. Restricting to
     * EntityTranslationDefinition guarantees a `language_id` column and a single
     * parent-FK column named `<parent_entity>_id`.
     *
     * @return array<int, array{table: string, column: string, idColumn: string}>
     */
    private function discoverSlotConfigTables(): array
    {
        $tables = [];

        foreach ($this->definitionRegistry->getDefinitions() as $definition) {
            if (!$definition instanceof EntityTranslationDefinition) {
                continue;
            }

            $parent = $definition->getParentDefinition();
            $idColumn = $parent->getEntityName() . '_id';

            foreach ($definition->getFields() as $field) {
                $isSlotConfigField = $field instanceof SlotConfigField;
                $isJsonSlotConfig = $field instanceof JsonField
                    && $field->getStorageName() === 'slot_config';

                if (!$isSlotConfigField && !$isJsonSlotConfig) {
                    continue;
                }

                $tables[] = [
                    'table' => $definition->getEntityName(),
                    'column' => $field->getStorageName(),
                    'idColumn' => $idColumn,
                ];
            }
        }

        return $tables;
    }

    /**
     * Scan every dynamically discovered slot-config column for rows whose JSON contains a
     * source=mapped customFields reference.
     *
     * @return array<string, array<int, array{table: string, column: string, entityId: string, languageId: string, paths: array<string>}>>
     */
    private function scanCmsTablesForMappedCustomFields(): array
    {
        $byField = [];

        foreach ($this->discoverSlotConfigTables() as $tableSpec) {
            $table = $tableSpec['table'];
            $column = $tableSpec['column'];
            $idColumn = $tableSpec['idColumn'];

            // Pre-filter at SQL level: rows must contain both 'customFields' and 'mapped' to be candidates.
            $sql = "SELECT LOWER(HEX(`{$idColumn}`)) AS entity_id, "
                . "LOWER(HEX(`language_id`)) AS language_id, "
                . "`{$column}` AS config_json "
                . "FROM `{$table}` "
                . "WHERE `{$column}` IS NOT NULL "
                . "AND `{$column}` LIKE '%customFields%' "
                . "AND `{$column}` LIKE '%mapped%'";

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

                $hits = $this->extractMappedCustomFieldKeysFromJson($configJson);
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
     * Extract customFields.<key> / customFields['<key>'] paths from any source=mapped node
     * inside a CMS slot config JSON string.
     *
     * @return array<string, list<string>>  fieldKey => list of full path strings
     */
    private function extractMappedCustomFieldKeysFromJson(string $jsonContent): array
    {
        if ($jsonContent === '') {
            return [];
        }

        $decoded = json_decode($jsonContent, true);
        if (!is_array($decoded)) {
            return [];
        }

        $found = [];
        $this->walkConfigForMappedCustomFields($decoded, $found);

        return $found;
    }

    /**
     * Recursive walker — populates $found with fieldKey => list<path>.
     * A "mapped" leaf is any associative array with source=mapped + string value;
     * its value is matched against the customFields regex and the leaf is not descended further.
     *
     * @param mixed $node
     * @param array<string, list<string>> $found
     */
    private function walkConfigForMappedCustomFields($node, array &$found): void
    {
        if (!is_array($node)) {
            return;
        }

        if (
            isset($node['source'], $node['value'])
            && is_string($node['source'])
            && $node['source'] === 'mapped'
            && is_string($node['value'])
        ) {
            $value = $node['value'];

            // Dot access: ...customFields.<key>
            if (preg_match_all('/customFields\.(\w+)/', $value, $matches)) {
                foreach ($matches[1] as $key) {
                    if (!isset($found[$key])) {
                        $found[$key] = [];
                    }
                    if (!in_array($value, $found[$key], true)) {
                        $found[$key][] = $value;
                    }
                }
            }

            // Bracket access: ...customFields['<key>'] or customFields["<key>"]
            if (preg_match_all('/customFields\[[\'"](\w+)[\'"]\]/', $value, $matches)) {
                foreach ($matches[1] as $key) {
                    if (!isset($found[$key])) {
                        $found[$key] = [];
                    }
                    if (!in_array($value, $found[$key], true)) {
                        $found[$key][] = $value;
                    }
                }
            }

            return;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->walkConfigForMappedCustomFields($child, $found);
            }
        }
    }
}
