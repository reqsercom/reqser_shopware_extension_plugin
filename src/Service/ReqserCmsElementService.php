<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for handling Shopware CMS element structure analysis
 * 
 * This service helps identify and validate JSON columns that contain
 * CMS element configurations (like slot_config, config fields in CMS tables)
 */
class ReqserCmsElementService
{
    private Connection $connection;
    private ContainerInterface $container;

    public function __construct(Connection $connection, ContainerInterface $container)
    {
        $this->connection = $connection;
        $this->container = $container;
    }

    /**
     * Check if a column contains CMS element configuration data
     * 
     * Determines if a JSON column is part of Shopware's CMS system by checking:
     * - Common CMS column naming patterns
     * - Table name patterns (cms_* tables)
     * - Column name patterns (contains 'slot', 'config', 'cms')
     * 
     * @param string $tableName The table name
     * @param string $columnName The column name
     * @return bool True if this is a CMS element configuration column
     */
    public function isCmsElementColumn(string $tableName, string $columnName): bool
    {
        // Check if it's a CMS-related table
        $isCmsTable = str_starts_with($tableName, 'cms_');
        
        // Dynamic pattern matching for CMS-related column names
        // Matches: slot_config, config, cms_config, slot_data, cms_slots, etc.
        $columnLower = strtolower($columnName);
        $hasCmsPattern = str_contains($columnLower, 'slot') 
                        || str_contains($columnLower, 'cms')
                        || ($columnLower === 'config' && $isCmsTable);
        
        return $hasCmsPattern || $isCmsTable;
    }

    /**
     * Get CMS element types used in a specific JSON column
     * 
     * Analyzes the JSON data in a column to extract CMS element type information
     * and identify which fields are translatable. Returns full paths to translatable values.
     * 
     * Example: For cms_slot_translation.config, returns paths like "content.value", "title.value"
     * where .value is the actual translatable content within Shopware's config structure.
     * 
     * @param string $tableName The table name
     * @param string $columnName The column name
     * @return array{isCmsColumn: bool, elementTypes: array<string>, translatableFieldsFound: array<string>}
     *         translatableFieldsFound contains full paths like ["content.value", "title.value"]
     */
    public function analyzeCmsColumnStructure(string $tableName, string $columnName): array
    {
        $isCmsColumn = $this->isCmsElementColumn($tableName, $columnName);
        
        if (!$isCmsColumn) {
            return [
                'isCmsColumn' => false
            ];
        }

        // Get a sample of JSON data to analyze structure
        try {
            $query = "SELECT `{$columnName}` FROM `{$tableName}` 
                      WHERE `{$columnName}` IS NOT NULL 
                      AND JSON_TYPE(`{$columnName}`) IS NOT NULL 
                      LIMIT 1";
            
            $result = $this->connection->fetchOne($query);
            
            if ($result === false) {
                return [
                    'isCmsColumn' => true
                ];
            }

            $jsonData = json_decode($result, true);
            
            // Try to extract element types from the JSON data itself
            $elementTypes = $this->extractElementTypes($jsonData);
            
            // If no types found in JSON and this looks like a slot config, try to get type from parent table
            if (empty($elementTypes) && $this->isSlotConfigColumn($tableName, $columnName)) {
                $elementTypes = $this->getElementTypeFromParentTable($tableName, $columnName);
            }
            
            $translatableFields = $this->identifyTranslatableFields($jsonData, $elementTypes);

            // Build response with only non-empty values
            $response = ['isCmsColumn' => true];
            
            if (!empty($elementTypes)) {
                $response['elementTypes'] = $elementTypes;
            }
            
            if (!empty($translatableFields)) {
                $response['translatableFieldsFound'] = $translatableFields;
            }

            return $response;

        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Failed to analyze CMS column structure for '{$tableName}.{$columnName}': " . $e->getMessage()
            );
        }
    }

    /**
     * Check if a column is a slot configuration column that has a parent table with type information
     * 
     * @param string $tableName
     * @param string $columnName
     * @return bool
     */
    private function isSlotConfigColumn(string $tableName, string $columnName): bool
    {
        // Check if column name indicates slot config
        if (!in_array($columnName, ['config', 'slot_config'], true)) {
            return false;
        }
        
        // Check if table name indicates this is a translation table with potential parent
        return str_ends_with($tableName, '_translation') || str_contains($tableName, '_slot');
    }
    
    /**
     * Try to get element type from parent table by inferring the relationship
     * 
     * Generic approach that works for various tables:
     * - cms_slot_translation → cms_slot
     * - category_translation (with slot_config) → could join with cms_slot via relationships
     * 
     * @param string $tableName
     * @param string $columnName
     * @return array<string> Element types found, or empty array
     */
    private function getElementTypeFromParentTable(string $tableName, string $columnName): array
    {
        try {
            // Try to infer parent table and foreign key
            $parentTable = null;
            $foreignKey = null;
            
            // Pattern 1: cms_slot_translation → cms_slot
            if (str_ends_with($tableName, '_translation')) {
                $parentTable = str_replace('_translation', '', $tableName);
                $foreignKey = $parentTable . '_id';
            }
            
            if ($parentTable && $foreignKey) {
                // Check if parent table has a 'type' column
                $schemaQuery = "SHOW COLUMNS FROM `{$parentTable}` LIKE 'type'";
                $hasTypeColumn = $this->connection->fetchOne($schemaQuery);
                
                if ($hasTypeColumn !== false) {
                    // Query to get type from parent table
                    $query = "SELECT p.`type` 
                              FROM `{$tableName}` t
                              INNER JOIN `{$parentTable}` p ON t.`{$foreignKey}` = p.id
                              WHERE t.`{$columnName}` IS NOT NULL 
                              AND JSON_TYPE(t.`{$columnName}`) IS NOT NULL 
                              LIMIT 1";
                    
                    $type = $this->connection->fetchOne($query);
                    
                    if ($type !== false && is_string($type)) {
                        return [$type];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently fail and return empty array - will fall back to searching all definitions
        }
        
        return [];
    }

    /**
     * Find all paths to translatable content within a field's structure
     * 
     * Recursively explores a field to find all paths that contain actual translatable content.
     * This handles various structures like:
     * - {value: "text", source: "static"} → ["value"]
     * - {title: {value: "text"}, subtitle: {value: "text"}} → ["title.value", "subtitle.value"]
     * - ["item1", "item2"] → ["0", "1"]
     * 
     * @param mixed $data The field data to explore
     * @return array<string> List of paths to translatable content (relative to the field)
     */
    private function findTranslatableContentPaths($data): array
    {
        $paths = [];
        
        if (!is_array($data)) {
            // Not an array - no nested structure to explore
            return [];
        }
        
        // Recursively explore the array/object structure
        foreach ($data as $key => $value) {
            if (is_string($value) && !empty($value)) {
                // Found string content
                // Skip metadata fields like 'source', 'entity', 'required'
                if (!in_array($key, ['source', 'entity', 'required', 'criteria', 'name'], true)) {
                    $paths[] = (string)$key;
                }
            } elseif (is_array($value)) {
                // Recursively explore nested structures
                $nestedPaths = $this->findTranslatableContentPaths($value);
                foreach ($nestedPaths as $nestedPath) {
                    $paths[] = "{$key}.{$nestedPath}";
                }
            }
        }
        
        return $paths;
    }

    /**
     * Extract CMS element types from JSON structure
     * 
     * @param mixed $jsonData Decoded JSON data
     * @return array<string> List of element types found
     */
    private function extractElementTypes($jsonData): array
    {
        $elementTypes = [];

        if (!is_array($jsonData)) {
            return $elementTypes;
        }

        // Check for CMS slot structure (array of slots)
        foreach ($jsonData as $key => $value) {
            if (is_array($value)) {
                // Check for 'type' key which indicates CMS element type
                if (isset($value['type'])) {
                    $elementTypes[] = $value['type'];
                }
                
                // Recursive check for nested structures
                $nestedTypes = $this->extractElementTypes($value);
                $elementTypes = array_merge($elementTypes, $nestedTypes);
            }
        }

        return array_unique($elementTypes);
    }

    /**
     * Identify which fields in the JSON data are translatable
     * Based ONLY on discovered element type definitions from Shopware
     * No hardcoded field lists - fully dynamic discovery
     * 
     * Returns full paths to translatable values:
     * - For Shopware config fields: "fieldName.value" (e.g., "content.value")
     * - For other structures: "fieldName" or "parent.child.fieldName"
     * 
     * @param mixed $jsonData Decoded JSON data
     * @param array<string> $elementTypes Element types found in the data
     * @return array<string> List of translatable field paths (e.g., ["content.value", "title.value"])
     */
    private function identifyTranslatableFields($jsonData, array $elementTypes): array
    {
        if (!is_array($jsonData)) {
            return [];
        }

        $translatableFields = [];
        $definitions = $this->getAllCmsElementDefinitions();

        // If no element types found, check ALL element definitions for any matching translatable fields
        // This handles cases like cms_slot_translation.config where element type is not stored in JSON
        $searchAllDefinitions = empty($elementTypes);

        // Recursively check for translatable fields based ONLY on discovered schemas
        foreach ($jsonData as $key => $value) {
            $fieldIsTranslatable = false;
            
            if ($searchAllDefinitions) {
                // Check if ANY element type defines this field as translatable
                foreach ($definitions as $elementType => $definition) {
                    if (isset($definition['fields'][$key]) && 
                        isset($definition['fields'][$key]['isTranslatable']) && 
                        $definition['fields'][$key]['isTranslatable'] === true) {
                        $fieldIsTranslatable = true;
                        break;
                    }
                }
            } else {
                // Check based on specific element types found in the data
                foreach ($elementTypes as $elementType) {
                    if (isset($definitions[$elementType]['fields'])) {
                        $fields = $definitions[$elementType]['fields'];
                        
                        // Check if this field is marked as translatable in the discovered schema
                        if (isset($fields[$key]) && isset($fields[$key]['isTranslatable']) && $fields[$key]['isTranslatable'] === true) {
                            $fieldIsTranslatable = true;
                            break;
                        }
                    }
                }
            }
            
            // If this field is marked as translatable, we need to find the actual content path(s) within it
            if ($fieldIsTranslatable) {
                if (is_array($value)) {
                    // Recursively explore the field's structure to find all translatable content paths
                    $paths = $this->findTranslatableContentPaths($value);
                    if (empty($paths)) {
                        // No translatable content found in nested structure, add the field name itself
                        $translatableFields[] = $key;
                    } else {
                        // Add all discovered paths with the field name as prefix
                        foreach ($paths as $path) {
                            $translatableFields[] = "{$key}.{$path}";
                        }
                    }
                } else {
                    // Scalar value - the field itself contains the translatable content
                    $translatableFields[] = $key;
                }
            }

            // Also recursively check nested structures that aren't marked as translatable fields
            // This handles complex nested JSON structures
            if (!$fieldIsTranslatable && is_array($value)) {
                $nestedFields = $this->identifyTranslatableFields($value, $elementTypes);
                foreach ($nestedFields as $nestedField) {
                    $translatableFields[] = "{$key}.{$nestedField}";
                }
            }
        }

        return array_unique($translatableFields);
    }

    /**
     * Get all CMS element types and their field schemas from Shopware core files AND custom plugins
     * Parses element registration files to discover structure
     * 
     * @return array<string, array{fields: array, description: string, source: string}>
     *         Array keyed by element type (e.g., 'text', 'image', 'form')
     */
    public function getAllCmsElementDefinitions(): array
    {
        $definitions = [];
        
        // 1. Scan Shopware CORE elements
        $definitions = array_merge($definitions, $this->scanCmsElementsInDirectory(
            '/vendor/shopware/administration/Resources/app/administration/src/module/sw-cms/elements',
            'core'
        ));
        
        // 2. Scan CUSTOM PLUGIN elements
        $definitions = array_merge($definitions, $this->scanCustomPluginCmsElements());
        
        ksort($definitions);
        
        return $definitions;
    }
    
    /**
     * Scan custom plugins for CMS element definitions
     * 
     * @return array<string, array{fields: array, description: string, source: string}>
     */
    private function scanCustomPluginCmsElements(): array
    {
        $definitions = [];
        
        try {
            $projectDir = $this->container->getParameter('kernel.project_dir');
            $customPluginsPath = $projectDir . '/custom/plugins';
            
            if (!is_dir($customPluginsPath)) {
                return $definitions;
            }
            
            // Scan all plugin directories
            $pluginDirs = scandir($customPluginsPath);
            
            foreach ($pluginDirs as $pluginDir) {
                if ($pluginDir === '.' || $pluginDir === '..') {
                    continue;
                }
                
                $pluginPath = $customPluginsPath . '/' . $pluginDir;
                
                if (!is_dir($pluginPath)) {
                    continue;
                }
                
                // Check if plugin has CMS elements
                $cmsElementsPath = $pluginPath . '/src/Resources/app/administration/src/module/sw-cms/elements';
                
                if (is_dir($cmsElementsPath)) {
                    $pluginElements = $this->scanCmsElementsInDirectory(
                        '/custom/plugins/' . $pluginDir . '/src/Resources/app/administration/src/module/sw-cms/elements',
                        'plugin:' . $pluginDir
                    );
                    
                    $definitions = array_merge($definitions, $pluginElements);
                }
            }
            
        } catch (\Throwable $e) {
            // Silently fail to not break the API
        }
        
        return $definitions;
    }
    
    /**
     * Scan a specific directory for CMS element definitions
     * 
     * @param string $relativePath Relative path from project root
     * @param string $source Source identifier (e.g., 'core', 'plugin:PluginName')
     * @return array<string, array{fields: array, description: string, source: string}>
     */
    private function scanCmsElementsInDirectory(string $relativePath, string $source): array
    {
        $definitions = [];
        
        try {
            $projectDir = $this->container->getParameter('kernel.project_dir');
            $elementsPath = $projectDir . $relativePath;
            
            if (!is_dir($elementsPath)) {
                return $definitions;
            }
            
            // Scan all element directories
            $elementDirs = scandir($elementsPath);
            
            foreach ($elementDirs as $elementDir) {
                if ($elementDir === '.' || $elementDir === '..' || $elementDir === 'index.ts') {
                    continue;
                }
                
                $elementPath = $elementsPath . '/' . $elementDir;
                
                if (is_dir($elementPath)) {
                    $indexFiles = ['index.ts', 'index.js'];
                    
                    foreach ($indexFiles as $indexFile) {
                        $indexPath = $elementPath . '/' . $indexFile;
                        
                        if (file_exists($indexPath)) {
                            $schema = $this->parseElementSchema($indexPath, $elementDir);
                            
                            if ($schema) {
                                // Add source information
                                $schema['source'] = $source;
                                $definitions[$elementDir] = $schema;
                            }
                            break;
                        }
                    }
                }
            }
            
        } catch (\Throwable $e) {
            // Silently fail to not break the API
        }
        
        return $definitions;
    }
    
    /**
     * Parse an element's index file to extract schema
     * 
     * @param string $filePath
     * @param string $elementType
     * @return array|null
     */
    private function parseElementSchema(string $filePath, string $elementType): ?array
    {
        try {
            $content = file_get_contents($filePath);
            
            if ($content === false) {
                return null;
            }
            
            // Find defaultConfig block - need to match balanced braces
            $startPos = strpos($content, 'defaultConfig:');
            if ($startPos === false) {
                return null;
            }
            
            // Find the opening brace
            $braceStart = strpos($content, '{', $startPos);
            if ($braceStart === false) {
                return null;
            }
            
            // Count braces to find matching closing brace
            $braceCount = 1;
            $pos = $braceStart + 1;
            $length = strlen($content);
            
            while ($pos < $length && $braceCount > 0) {
                if ($content[$pos] === '{') {
                    $braceCount++;
                } elseif ($content[$pos] === '}') {
                    $braceCount--;
                }
                $pos++;
            }
            
            if ($braceCount !== 0) {
                return null;
            }
            
            $configBlock = substr($content, $braceStart + 1, $pos - $braceStart - 2);
            $fields = $this->parseConfigFields($configBlock);
            
            // Also parse the config template to get component types
            $elementDir = dirname($filePath);
            $configTemplates = $this->parseConfigTemplates($elementDir . '/config');
            
            // Enhance field information with component types from templates
            $fields = $this->enhanceFieldsWithComponentInfo($fields, $configTemplates);
            
            return [
                'fields' => $fields,
                'description' => $this->getElementDescription($elementType, ''),
                'source' => '' // Will be set by caller
            ];
            
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Parse config template files to extract Shopware component usage
     * 
     * @param string $configDir
     * @return array Field name => component info
     */
    private function parseConfigTemplates(string $configDir): array
    {
        $componentInfo = [];
        
        if (!is_dir($configDir)) {
            return $componentInfo;
        }
        
        $files = glob($configDir . '/*.twig');
        if (!$files) {
            $files = [];
        }
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            // Look for Shopware component usage like <sw-text-editor ... element.config.content.value>
            // Pattern: <sw-XXX-XXX ... element.config.FIELDNAME.value
            if (preg_match_all('/<(sw-[a-z-]+)[^>]*element\.config\.([a-zA-Z]+)\.value/m', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $component = $match[1]; // e.g., 'sw-text-editor'
                    $fieldName = $match[2]; // e.g., 'content'
                    
                    if (!isset($componentInfo[$fieldName])) {
                        $componentInfo[$fieldName] = [];
                    }
                    $componentInfo[$fieldName]['component'] = $component;
                }
            }
        }
        
        return $componentInfo;
    }
    
    /**
     * Enhance field information with component types from templates
     * 
     * @param array $fields
     * @param array $componentInfo
     * @return array
     */
    private function enhanceFieldsWithComponentInfo(array $fields, array $componentInfo): array
    {
        foreach ($fields as $idx => $field) {
            $fieldName = $field['name'] ?? '';
            
            if (isset($componentInfo[$fieldName]['component'])) {
                $component = $componentInfo[$fieldName]['component'];
                
                // Append component info to the field's source for analysis
                if (isset($fields[$idx]['source'])) {
                    $fields[$idx]['source'] .= "\nComponent: " . $component;
                } else {
                    $fields[$idx]['source'] = "Component: " . $component;
                }
                
                // Re-determine field type based on the actual component used
                $fields[$idx]['type'] = $this->determineFieldTypeFromComponent($component, $fields[$idx]['source'] ?? '');
                $fields[$idx]['isTranslatable'] = $this->isTranslatableField($fieldName, $fields[$idx]['source'] ?? '', $fields[$idx]['type']);
                $fields[$idx]['isUrl'] = ($fields[$idx]['type'] === 'url');
                
                // Re-add expectedPaths if field is translatable
                if ($fields[$idx]['isTranslatable']) {
                    $fields[$idx]['expectedPaths'] = $this->getExpectedTranslatablePaths($fieldName, $fields[$idx]['source'] ?? '');
                }
            }
        }
        
        return $fields;
    }
    
    /**
     * Determine field type based on the actual Shopware component used
     * 
     * @param string $component
     * @param string $fieldConfig
     * @return string
     */
    private function determineFieldTypeFromComponent(string $component, string $fieldConfig): string
    {
        // Map Shopware components to field types
        $componentMapping = [
            'sw-text-editor' => 'text',
            'sw-text-field' => 'text',
            'sw-textarea-field' => 'text',
            'sw-code-editor' => 'text',
            'sw-url-field' => 'url',
            'sw-link-field' => 'url',
            'sw-media-field' => 'media',
            'sw-media-upload' => 'media',
            'sw-media-selection' => 'media',
            'sw-entity-single-select' => 'reference',
            'sw-entity-multi-select' => 'reference',
            'sw-switch-field' => 'boolean',
            'sw-checkbox-field' => 'boolean',
            'sw-select-field' => 'config',
            'sw-single-select' => 'config',
            'sw-multi-select' => 'config',
            'sw-number-field' => 'styling',
            'mt-select' => 'config',
            'mt-text-field' => 'text',
            'mt-textarea' => 'text',
        ];
        
        foreach ($componentMapping as $componentPattern => $type) {
            if (str_contains($component, $componentPattern)) {
                return $type;
            }
        }
        
        // Fallback to original method if component not recognized
        return $this->determineFieldType('', $fieldConfig);
    }
    
    /**
     * Parse config fields from defaultConfig block
     * 
     * @param string $configBlock
     * @return array
     */
    private function parseConfigFields(string $configBlock): array
    {
        $fields = [];
        $lines = explode("\n", $configBlock);
        $currentField = null;
        $fieldContent = '';
        $braceCount = 0;
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            // Skip empty lines and comments
            if (empty($trimmedLine) || strpos($trimmedLine, '//') === 0) {
                continue;
            }
            
            // Check if this line starts a new field definition
            if (preg_match('/^(\w+)\s*:\s*\{/', $trimmedLine, $matches) && $braceCount === 0) {
                // Save previous field if exists
                if ($currentField !== null && !empty($fieldContent)) {
                    $fields[$currentField] = $this->createFieldDefinition($currentField, $fieldContent);
                }
                
                $currentField = $matches[1];
                $fieldContent = $trimmedLine;
                $braceCount = substr_count($trimmedLine, '{') - substr_count($trimmedLine, '}');
            } else if ($currentField !== null) {
                $fieldContent .= ' ' . $trimmedLine;
                $braceCount += substr_count($trimmedLine, '{') - substr_count($trimmedLine, '}');
                
                // Field definition complete
                if ($braceCount === 0 && strpos($trimmedLine, '}') !== false) {
                    $fields[$currentField] = $this->createFieldDefinition($currentField, $fieldContent);
                    $currentField = null;
                    $fieldContent = '';
                }
            }
        }
        
        // Handle last field
        if ($currentField !== null && !empty($fieldContent)) {
            $fields[$currentField] = $this->createFieldDefinition($currentField, $fieldContent);
        }
        
        return $fields;
    }
    
    /**
     * Create field definition from parsed content
     * 
     * @param string $fieldName
     * @param string $fieldContent
     * @return array
     */
    private function createFieldDefinition(string $fieldName, string $fieldContent): array
    {
        $fieldType = $this->determineFieldType($fieldName, $fieldContent);
        $isTranslatable = $this->isTranslatableField($fieldName, $fieldContent, $fieldType);
        $isUrl = ($fieldType === 'url');
        
        $definition = [
            'name' => $fieldName,
            'type' => $fieldType,
            'isTranslatable' => $isTranslatable,
            'isUrl' => $isUrl,
            'source' => $this->extractSource($fieldContent)
        ];
        
        // Add expected translatable paths for translatable fields
        // This helps API consumers know exactly where to find translatable content
        if ($isTranslatable) {
            $definition['expectedPaths'] = $this->getExpectedTranslatablePaths($fieldName, $fieldContent);
        }
        
        return $definition;
    }
    
    /**
     * Get expected translatable paths for a field based on Shopware config patterns
     * 
     * All Shopware CMS element config fields use the standard structure: {value: ..., source: ...}
     * This is fundamental to how Shopware stores CMS element configurations in the database.
     * 
     * @param string $fieldName
     * @param string $fieldContent (unused, kept for potential future use)
     * @return array<string>
     */
    private function getExpectedTranslatablePaths(string $fieldName, string $fieldContent): array
    {
        // For Shopware CMS elements, the translatable content is ALWAYS at fieldName.value
        // This is the standard Shopware config field structure used by all CMS elements
        return ["{$fieldName}.value"];
    }
    
    /**
     * Determine the type of a field based on its actual Shopware configuration
     * Analyzes the field config from Shopware's CMS element definitions
     * 
     * @param string $fieldName
     * @param string $fieldConfig
     * @return string
     */
    private function determineFieldType(string $fieldName, string $fieldConfig): string
    {
        // 1. Check for explicit type in config (most reliable - directly from Shopware config)
        if (preg_match("/type\s*:\s*['\"](\w+)['\"]/", $fieldConfig, $matches)) {
            $configType = $matches[1];
            
            // Map Shopware config types to our types
            switch ($configType) {
                case 'text':
                case 'textarea':
                case 'html':
                case 'string':
                    return 'text';
                case 'url':
                case 'link':
                    return 'url';
                case 'media':
                case 'image':
                    return 'media';
                case 'select':
                case 'switch':
                case 'checkbox':
                case 'bool':
                case 'boolean':
                    return 'config';
                case 'number':
                case 'int':
                case 'float':
                    return 'styling';
            }
        }
        
        // 2. Check for source type (Shopware's way of defining data sources)
        if (preg_match("/source\s*:\s*['\"]([^'\"]+)['\"]/", $fieldConfig, $matches)) {
            $source = $matches[1];
            
            if (str_contains($source, 'media')) {
                return 'media';
            }
            if (str_contains($source, 'url') || str_contains($source, 'link')) {
                return 'url';
            }
            if (str_contains($source, 'product') || str_contains($source, 'category')) {
                return 'reference';
            }
        }
        
        // 3. Check for entity/entity-selection (Shopware entity references)
        if (str_contains($fieldConfig, 'entity:') || str_contains($fieldConfig, 'entity-selection')) {
            return 'reference';
        }
        
        // 4. Analyze Shopware component types used in the config
        // These are actual Shopware components, not assumptions
        
        // Text input components (translatable content)
        if (preg_match('/sw-text-field|sw-text-editor|sw-textarea|sw-code-editor/i', $fieldConfig)) {
            return 'text';
        }
        
        // Media components
        if (preg_match('/sw-media-field|sw-media-upload|sw-media-selection|media-compact-selection/i', $fieldConfig)) {
            return 'media';
        }
        
        // URL/Link components
        if (preg_match('/sw-url-field|sw-link-field/i', $fieldConfig)) {
            return 'url';
        }
        
        // Boolean/Switch components
        if (preg_match('/sw-switch-field|sw-checkbox-field/i', $fieldConfig)) {
            return 'boolean';
        }
        
        // Select/Dropdown components
        if (preg_match('/sw-select-field|sw-single-select|sw-multi-select/i', $fieldConfig)) {
            return 'config';
        }
        
        // Number components
        if (preg_match('/sw-number-field/i', $fieldConfig)) {
            return 'styling';
        }
        
        // 5. Check for default value patterns that indicate type
        if (preg_match('/value\s*:\s*(true|false)/i', $fieldConfig)) {
            return 'boolean';
        }
        
        if (preg_match('/value\s*:\s*\d+/i', $fieldConfig)) {
            return 'styling';
        }
        
        if (preg_match("/value\s*:\s*['\"][^'\"]*['\"]/", $fieldConfig)) {
            // Has a string default value - but we can't assume it's text without more info
            // Could be a config option, so leave as unknown
        }
        
        // If we can't determine the type from Shopware's configuration, return unknown
        // This field will not be marked as translatable unless Shopware explicitly configured it
        return 'unknown';
    }
    
    /**
     * Determine if a field is translatable based on Shopware's configuration
     * 
     * @param string $fieldName
     * @param string $fieldConfig
     * @param string $fieldType
     * @return bool
     */
    private function isTranslatableField(string $fieldName, string $fieldConfig, string $fieldType): bool
    {
        // Only text content fields are translatable
        if ($fieldType === 'text') {
            return true;
        }
        
        // URL, styling, config, boolean, reference fields are NOT translatable
        if (in_array($fieldType, ['url', 'styling', 'config', 'boolean', 'reference'])) {
            return false;
        }
        
        // Media fields: only if Shopware's config explicitly indicates translatable alt text
        // Check if the config has a component for alt text
        if ($fieldType === 'media' && preg_match('/alt.*sw-text-field/i', $fieldConfig)) {
            return true;
        }
        
        // Unknown types: Cannot determine translatability without Shopware configuration
        if ($fieldType === 'unknown') {
            return false;
        }
        
        return false;
    }
    
    /**
     * Extract source type from field config
     * 
     * @param string $fieldConfig
     * @return string
     */
    private function extractSource(string $fieldConfig): string
    {
        if (preg_match("/source\s*:\s*['\"](\w+)['\"]/", $fieldConfig, $matches)) {
            return $matches[1];
        }
        
        return 'static';
    }
    
    /**
     * Get a human-readable description for a CMS element type
     * Based on the element type and class name
     * 
     * @param string $elementType
     * @param string $className
     * @return string
     */
    private function getElementDescription(string $elementType, string $className): string
    {
        // Try to extract from class name first
        if (!empty($className)) {
            $classShortName = substr($className, strrpos($className, '\\') + 1);
            $classShortName = str_replace('CmsElementResolver', '', $classShortName);
            $classShortName = str_replace('Cms', '', $classShortName);
            
            // Convert CamelCase to readable format
            $description = preg_replace('/([a-z])([A-Z])/', '$1 $2', $classShortName);
            $description = trim($description);
            
            if (!empty($description)) {
                return $description . ' element';
            }
        }
        
        // Fallback: Use element type and make it readable
        // Convert kebab-case to title case (e.g., "product-slider" -> "Product Slider")
        $readable = str_replace('-', ' ', $elementType);
        $readable = ucwords($readable);
        
        return $readable . ' element';
    }
}

