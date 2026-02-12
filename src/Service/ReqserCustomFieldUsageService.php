<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Finder\Finder;
use Twig\Loader\FilesystemLoader;

/**
 * Service for analyzing which custom fields are actually referenced in Twig templates.
 * 
 * Uses Shopware's native Twig FilesystemLoader to discover all registered template
 * directories (core, plugins, apps, themes) and Symfony Finder to scan them.
 * Cross-references found keys against the custom_field table to return only
 * actual registered custom fields, including the entity they belong to and
 * how they are accessed (translated vs direct).
 */
class ReqserCustomFieldUsageService
{
    private Connection $connection;
    private FilesystemLoader $loader;

    public function __construct(Connection $connection, FilesystemLoader $loader)
    {
        $this->connection = $connection;
        $this->loader = $loader;
    }

    /**
     * Analyze which registered custom fields are referenced in Twig template files.
     * 
     * @return array{fields: array<int, array{name: string, type: string, entities: array<string>, twigFiles: array<int, array{file: string, accessPatterns: array<string>, references: array<string>}>}>, totalCustomFields: int, displayedCustomFields: int}
     */
    public function getCustomFieldTwigUsage(): array
    {
        // 1. Get all registered custom field names, types, and entity assignments from the database
        $registeredFields = $this->getRegisteredCustomFields();

        // 2. Get all template directories from Shopware's Twig loader
        $templateDirs = $this->getTemplateDirs();

        // 3. Scan all .html.twig files for customFields references (with access pattern info)
        $twigUsageMap = $this->scanTwigFiles($templateDirs);

        // 4. Cross-reference: only return keys that are actual registered custom fields
        $fields = [];
        foreach ($registeredFields as $fieldName => $fieldInfo) {
            if (isset($twigUsageMap[$fieldName])) {
                $twigFiles = [];
                foreach ($twigUsageMap[$fieldName] as $fileName => $fileData) {
                    $twigFiles[] = [
                        'file' => $fileName,
                        'accessPatterns' => array_keys($fileData['accessPatterns']),
                        'references' => array_keys($fileData['references']),
                    ];
                }

                $fields[] = [
                    'name' => $fieldName,
                    'type' => $fieldInfo['type'],
                    'entities' => $fieldInfo['entities'],
                    'twigFiles' => $twigFiles,
                ];
            }
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
     * @param array<string> $dirs
     * @return array<string, array<string, array{accessPatterns: array<string, true>, references: array<string, true>}>> Map of fieldKey => [twigFileName => [data]]
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
     * Extract custom field key names, their access patterns, and the full Twig expressions.
     * 
     * Returns a map of fieldKey => ['accessPatterns' => [...], 'references' => [...]].
     * 
     * @param string $content
     * @return array<string, array{accessPatterns: array<string>, references: array<string>}>
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
