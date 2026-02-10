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
 * actual registered custom fields.
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
     * @return array{fields: array<int, array{name: string, type: string, twigFiles: array<string>}>, totalCustomFields: int, displayedCustomFields: int}
     */
    public function getCustomFieldTwigUsage(): array
    {
        // 1. Get all registered custom field names and types from the database
        $registeredFields = $this->getRegisteredCustomFields();

        // 2. Get all template directories from Shopware's Twig loader
        $templateDirs = $this->getTemplateDirs();

        // 3. Scan all .html.twig files for customFields references
        $twigUsageMap = $this->scanTwigFiles($templateDirs);

        // 4. Cross-reference: only return keys that are actual registered custom fields
        $fields = [];
        foreach ($registeredFields as $fieldName => $fieldType) {
            if (isset($twigUsageMap[$fieldName])) {
                $fields[] = [
                    'name' => $fieldName,
                    'type' => $fieldType,
                    'twigFiles' => array_values(array_unique($twigUsageMap[$fieldName])),
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
     * Get all active custom fields from the database (name => type).
     * Uses the same query as Shopware's CustomFieldService::getCustomFields().
     * 
     * @return array<string, string>
     */
    private function getRegisteredCustomFields(): array
    {
        return $this->connection->fetchAllKeyValue(
            'SELECT `name`, `type` FROM `custom_field` WHERE `active` = 1'
        );
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
     * Detects patterns:
     *   - .customFields.KEY       (dot access)
     *   - customFields['KEY']     (bracket access with single quotes)
     *   - customFields["KEY"]     (bracket access with double quotes)
     * 
     * @param array<string> $dirs
     * @return array<string, array<string>> Map of fieldKey => [twigFileName, ...]
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

            $keys = $this->extractCustomFieldKeys($content);

            foreach ($keys as $key) {
                $usageMap[$key][] = $fileName;
            }
        }

        return $usageMap;
    }

    /**
     * Extract custom field key names from Twig template content using regex.
     * 
     * @param string $content
     * @return array<string>
     */
    private function extractCustomFieldKeys(string $content): array
    {
        $keys = [];

        // Pattern 1: dot access — e.g. entity.customFields.myKey or entity.translated.customFields.myKey
        if (preg_match_all('/\.customFields\.(\w+)/', $content, $matches)) {
            $keys = array_merge($keys, $matches[1]);
        }

        // Pattern 2: bracket access — e.g. customFields['myKey'] or customFields["myKey"]
        if (preg_match_all('/customFields\[[\'"](\w+)[\'"]\]/', $content, $matches)) {
            $keys = array_merge($keys, $matches[1]);
        }

        return array_unique($keys);
    }
}
