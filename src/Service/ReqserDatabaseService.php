<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslatedField;

/**
 * Service for database-related operations
 */
class ReqserDatabaseService
{
    private Connection $connection;
    private LoggerInterface $logger;
    private DefinitionInstanceRegistry $definitionRegistry;
    private ReqserJsonFieldDetectionService $jsonFieldDetectionService;

    /**
     * @param Connection $connection
     * @param LoggerInterface $logger
     * @param DefinitionInstanceRegistry $definitionRegistry
     * @param ReqserJsonFieldDetectionService $jsonFieldDetectionService
     */
    public function __construct(
        Connection $connection,
        LoggerInterface $logger,
        DefinitionInstanceRegistry $definitionRegistry,
        ReqserJsonFieldDetectionService $jsonFieldDetectionService
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
        $this->definitionRegistry = $definitionRegistry;
        $this->jsonFieldDetectionService = $jsonFieldDetectionService;
    }

    /**
     * Dump every DAL entity definition registered in the installation.
     * Includes translation-entity linkage, translatable field list, and the source bundle/plugin name.
     *
     * @return array<int, array{
     *     entity: string,
     *     class: string,
     *     source: string,
     *     sourcePath: string,
     *     hasTranslation: bool,
     *     translationEntity: string|null,
     *     translatableFields: array<int, string>
     * }>
     */
    public function getEntityDefinitionsDump(): array
    {
        $projectDir = '';
        // Best-effort project dir resolution — EntityDefinition source paths
        // are prefixed with the real project dir.
        if (\defined('PHPUNIT_COMPOSER_INSTALL') === false
            && isset($_SERVER['DOCUMENT_ROOT'])
            && is_string($_SERVER['DOCUMENT_ROOT'])) {
            $projectDir = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
        }
        // Fallback: walk up from this file to locate composer.json of the
        // project (three levels above src/Service/ReqserDatabaseService.php).
        if ($projectDir === '' || !is_dir($projectDir . '/vendor')) {
            $candidate = \dirname(__DIR__, 5);
            if (is_dir($candidate . '/vendor')) {
                $projectDir = str_replace('\\', '/', $candidate);
            }
        }

        $out = [];

        foreach ($this->definitionRegistry->getDefinitions() as $definition) {
            try {
                $reflection = new \ReflectionClass($definition);
                $file = $reflection->getFileName();
                $fileNorm = is_string($file) ? str_replace('\\', '/', $file) : '';

                $relative = $fileNorm;
                if ($projectDir !== '' && str_starts_with($fileNorm, $projectDir)) {
                    $relative = ltrim(substr($fileNorm, strlen($projectDir)), '/');
                }

                if (str_contains($relative, 'vendor/shopware/')) {
                    $source = 'core';
                } elseif (str_contains($relative, 'custom/plugins/')) {
                    $after = substr($relative, strpos($relative, 'custom/plugins/') + strlen('custom/plugins/'));
                    $parts = explode('/', $after);
                    $source = $parts[0] ?? 'unknown';
                } else {
                    $source = 'unknown';
                }

                $translationEntity = null;
                try {
                    $translationDefinition = $definition->getTranslationDefinition();
                    if ($translationDefinition instanceof EntityDefinition) {
                        $translationEntity = $translationDefinition->getEntityName();
                    }
                } catch (\Throwable) {
                    // Definitions missing translation wiring — treat as no translation.
                }

                $translatableFields = [];
                try {
                    foreach ($definition->getFields() as $field) {
                        if ($field instanceof TranslatedField) {
                            $translatableFields[] = $field->getPropertyName();
                        }
                    }
                } catch (\Throwable) {
                    // Skip fields we cannot introspect; still emit the entity.
                }

                $out[] = [
                    'entity' => $definition->getEntityName(),
                    'class' => $reflection->getName(),
                    'source' => $source,
                    'sourcePath' => $relative,
                    'hasTranslation' => $translationEntity !== null,
                    'translationEntity' => $translationEntity,
                    'translatableFields' => $translatableFields,
                ];
            } catch (\Throwable) {
                // Never let a single broken definition break the dump.
                continue;
            }
        }

        usort($out, static fn(array $a, array $b): int => strcmp($a['entity'], $b['entity']));

        return $out;
    }

    /**
     * Get all database tables ending with _translation
     * 
     * @return array<int, string> Array of translation table names
     * @throws \RuntimeException If database query fails
     */
    public function getTranslationTables(): array
    {
        // Get the database name
        $databaseName = $this->connection->getDatabase();

        // Query to get all tables ending with _translation
        $sql = "
            SELECT TABLE_NAME 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = :database 
            AND TABLE_NAME LIKE '%_translation'
            ORDER BY TABLE_NAME
        ";

        $tables = $this->connection->fetchFirstColumn($sql, [
            'database' => $databaseName
        ]);

        return $tables;
    }

    /**
     * Validate that a table name is a legitimate translation table.
     * Checks the suffix is '_translation' AND the table exists in the database.
     *
     * @param string $tableName
     * @return void
     * @throws \InvalidArgumentException If table name doesn't end with '_translation' or doesn't exist
     */
    public function validateTranslationTable(string $tableName): void
    {
        if (!str_ends_with($tableName, '_translation')) {
            throw new \InvalidArgumentException("Table '$tableName' must end with '_translation'");
        }

        $tables = $this->getTranslationTables();

        if (!in_array($tableName, $tables, true)) {
            throw new \InvalidArgumentException("Table '$tableName' is not a valid translation table");
        }
    }

    /**
     * Get complete schema information for a translation table
     * Returns ALL columns plus a list of which columns are translatable
     *
     * @param string $tableName
     * @return array
     * @throws \InvalidArgumentException If table name is invalid
     * @throws \RuntimeException If database query fails
     */
    public function getTranslatableColumnsSchema(string $tableName): array
    {
        $this->validateTranslationTable($tableName);

        // Get ALL columns from the table
        $allColumns = $this->getTableFullSchema($tableName); // Already keyed by field name
        
        // Get translatable field names for this specific table
        $translatableFields = $this->getTranslatableFieldsForTable($tableName);
        
        return [
            'schema' => $allColumns,
            'translatableRows' => $translatableFields
        ];
    }

    /**
     * Get detailed information about a specific translatable column
     * Checks if the column type is JSON and returns extended details
     *
     * @param string $tableName
     * @param string $columnName
     * @param array $columnSchema
     * @return array
     */
    public function getTranslationTableRowDetails(string $tableName, string $columnName, array $columnSchema): array
    {
        $rowDetails = [];
        
        // Check if the column contains JSON data
        // MySQL can store JSON as native 'json' type OR as 'text'/'longtext'
        $type = strtolower($columnSchema['type'] ?? '');
        $isJson = $this->jsonFieldDetectionService->isJsonColumn($tableName, $columnName, $type);

        $rowDetails['isJson'] = $isJson;

        if ($isJson) {
            // Dynamically detect if this is a CMS element column using Shopware's entity definitions
            if ($this->jsonFieldDetectionService->isCmsElementColumn($tableName, $columnName)) {
                $rowDetails['jsonType'] = 'cms_element';
            } else {
                $rowDetails['jsonType'] = 'other';
            }
        }
        
        return [
            'columnName' => $columnName,
            'schema' => $columnSchema,
            'rowDetails' => $rowDetails
        ];
    }

    /**
     * Get translatable fields for a specific translation table
     * Uses direct entity lookup for maximum performance
     *
     * @param string $tableName
     * @return array
     */
    private function getTranslatableFieldsForTable(string $tableName): array
    {
        $translatableFields = [];

        try {
            // Extract parent entity name from translation table name
            // e.g., 'product_translation' -> 'product'
            $entityName = str_replace('_translation', '', $tableName);
            
            // Direct lookup of the entity definition (much faster than iterating)
            $definition = $this->definitionRegistry->getByEntityName($entityName);
            
            // Get all fields from the parent entity
            $fields = $definition->getFields();
            
            foreach ($fields as $field) {
                // Check if field is a TranslatedField
                if ($field instanceof TranslatedField) {
                    $propertyName = $field->getPropertyName();
                    
                    // Convert camelCase to snake_case for database column names
                    $columnName = $this->camelCaseToSnakeCase($propertyName);
                    $translatableFields[] = $columnName;
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to get translatable fields for table '$tableName': " . $e->getMessage());
        }

        return $translatableFields;
    }

    /**
     * Get full schema information for a table (including all columns, not just translatable ones)
     * Uses SHOW COLUMNS command for standard MySQL schema format
     *
     * @param string $tableName
     * @return array
     * @throws \RuntimeException If schema query fails
     */
    private function getTableFullSchema(string $tableName): array
    {
        // Use SHOW COLUMNS for standard MySQL schema format
        $sql = "SHOW COLUMNS FROM `" . $tableName . "`";
        
        $columnsRaw = $this->connection->fetchAllAssociative($sql);
        
        if ($columnsRaw === false) {
            throw new \RuntimeException("Failed to retrieve schema for table '$tableName'");
        }
        
        // Transform to associative array with lowercase keys
        $columns = [];
        foreach ($columnsRaw as $column) {
            $fieldName = $column['Field'];
            
            // Convert all keys to lowercase and include all fields dynamically
            $columnData = [];
            foreach ($column as $key => $value) {
                $columnData[strtolower($key)] = $value;
            }
            
            $columns[$fieldName] = $columnData;
        }
        
        return $columns;
    }

    /**
     * Convert camelCase to snake_case
     *
     * @param string $input
     * @return string
     */
    private function camelCaseToSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input));
    }
}

