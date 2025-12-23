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
     * and identify which fields are translatable
     * 
     * @param string $tableName The table name
     * @param string $columnName The column name
     * @return array{isCmsColumn: bool, elementTypes: array<string>, sampleStructure: array|null, translatableFieldsFound: array<string>}
     */
    public function analyzeCmsColumnStructure(string $tableName, string $columnName): array
    {
        $isCmsColumn = $this->isCmsElementColumn($tableName, $columnName);
        
        if (!$isCmsColumn) {
            return [
                'isCmsColumn' => false,
                'elementTypes' => [],
                'sampleStructure' => null,
                'translatableFieldsFound' => []
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
                    'isCmsColumn' => true,
                    'elementTypes' => [],
                    'sampleStructure' => null,
                    'translatableFieldsFound' => []
                ];
            }

            $jsonData = json_decode($result, true);
            $elementTypes = $this->extractElementTypes($jsonData);
            $translatableFields = $this->identifyTranslatableFields($jsonData, $elementTypes);

            return [
                'isCmsColumn' => true,
                'elementTypes' => $elementTypes,
                'sampleStructure' => $this->getSampleStructure($jsonData),
                'translatableFieldsFound' => $translatableFields
            ];

        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Failed to analyze CMS column structure for '{$tableName}.{$columnName}': " . $e->getMessage()
            );
        }
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
     * Get a simplified sample structure from JSON data
     * 
     * @param mixed $jsonData Decoded JSON data
     * @return array|null Simplified structure or null
     */
    private function getSampleStructure($jsonData): ?array
    {
        if (!is_array($jsonData) || empty($jsonData)) {
            return null;
        }

        // Return structure with keys only (no values) to show the schema
        $structure = [];
        
        foreach ($jsonData as $key => $value) {
            if (is_array($value)) {
                $structure[$key] = array_keys($value);
            } else {
                $structure[$key] = gettype($value);
            }
        }

        return $structure;
    }

    /**
     * Identify which fields in the JSON data are translatable
     * Based on element type and known translatable field patterns
     * 
     * @param mixed $jsonData Decoded JSON data
     * @param array<string> $elementTypes Element types found in the data
     * @return array<string> List of translatable field names found
     */
    private function identifyTranslatableFields($jsonData, array $elementTypes): array
    {
        if (!is_array($jsonData)) {
            return [];
        }

        $translatableFields = [];
        $definitions = $this->getAllCmsElementDefinitions();

        // Common translatable field names across all CMS elements
        $commonTranslatableFields = [
            'content', 'title', 'text', 'label', 'description', 
            'alt', 'url', 'videoTitle', 'confirmText', 'placeholder',
            'customText', 'buttonText'
        ];

        // Recursively check for translatable fields
        foreach ($jsonData as $key => $value) {
            // Check if this key is a known translatable field
            if (in_array($key, $commonTranslatableFields, true)) {
                $translatableFields[] = $key;
            }

            // Check based on element type definitions
            foreach ($elementTypes as $elementType) {
                if (isset($definitions[$elementType])) {
                    $definedTranslatableFields = $definitions[$elementType]['translatableFields'];
                    
                    // Handle simple fields
                    foreach ($definedTranslatableFields as $fieldKey => $fieldValue) {
                        if (is_numeric($fieldKey) && $fieldValue === $key) {
                            $translatableFields[] = $key;
                        }
                    }
                }
            }

            // Recursively check nested structures
            if (is_array($value)) {
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
     * @return array<string, array{elementType: string, fields: array, description: string, source: string}>
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
     * @return array<string, array{elementType: string, fields: array, description: string, source: string}>
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
     * @return array<string, array{elementType: string, fields: array, description: string, source: string}>
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
            
            return [
                'elementType' => $elementType,
                'fields' => $fields,
                'description' => $this->getElementDescription($elementType, '')
            ];
            
        } catch (\Throwable $e) {
            return null;
        }
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
        
        return [
            'name' => $fieldName,
            'type' => $fieldType,
            'isTranslatable' => $isTranslatable,
            'source' => $this->extractSource($fieldContent)
        ];
    }
    
    /**
     * Determine the type of a field based on its name and config
     * 
     * @param string $fieldName
     * @param string $fieldConfig
     * @return string
     */
    private function determineFieldType(string $fieldName, string $fieldConfig): string
    {
        // URL fields
        if (str_contains($fieldName, 'url') || str_contains($fieldName, 'Url') || str_contains($fieldName, 'link')) {
            return 'url';
        }
        
        // Media/Image fields
        if (str_contains($fieldName, 'media') || str_contains($fieldName, 'image') || str_contains($fieldName, 'Media')) {
            return 'media';
        }
        
        // Text content fields (translatable)
        if (in_array($fieldName, ['content', 'text', 'title', 'label', 'description', 'alt', 'placeholder', 'confirmText', 'customText', 'videoTitle'])) {
            return 'text';
        }
        
        // Boolean fields
        if (str_contains($fieldConfig, 'true') || str_contains($fieldConfig, 'false')) {
            return 'boolean';
        }
        
        // Styling fields (alignment, display, layout)
        if (str_contains($fieldName, 'Align') || str_contains($fieldName, 'align') || 
            str_contains($fieldName, 'Display') || str_contains($fieldName, 'display') ||
            str_contains($fieldName, 'Layout') || str_contains($fieldName, 'layout') ||
            str_contains($fieldName, 'Size') || str_contains($fieldName, 'size')) {
            return 'styling';
        }
        
        // Product/Category references
        if (str_contains($fieldName, 'product') || str_contains($fieldName, 'category')) {
            return 'reference';
        }
        
        // Array/list fields
        if (str_contains($fieldName, 'Items') || str_contains($fieldName, 'List') || str_contains($fieldName, 'fields')) {
            return 'array';
        }
        
        // Default to config
        return 'config';
    }
    
    /**
     * Determine if a field is translatable
     * 
     * @param string $fieldName
     * @param string $fieldConfig
     * @param string $fieldType
     * @return bool
     */
    private function isTranslatableField(string $fieldName, string $fieldConfig, string $fieldType): bool
    {
        // Text and URL fields are translatable
        if (in_array($fieldType, ['text', 'url'])) {
            return true;
        }
        
        // Styling and config fields are NOT translatable
        if (in_array($fieldType, ['styling', 'config', 'boolean', 'reference'])) {
            return false;
        }
        
        // Media can have translatable alt text
        if ($fieldType === 'media' && str_contains($fieldName, 'alt')) {
            return true;
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
        // Extract a readable name from the class name
        $classShortName = substr($className, strrpos($className, '\\') + 1);
        $classShortName = str_replace('CmsElementResolver', '', $classShortName);
        $classShortName = str_replace('Cms', '', $classShortName);
        
        // Convert CamelCase to readable format
        $description = preg_replace('/([a-z])([A-Z])/', '$1 $2', $classShortName);
        $description = trim($description) . ' element';
        
        return $description;
    }
}

