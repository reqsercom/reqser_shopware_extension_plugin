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
    private ReqserCmsElementService $cmsElementService;

    public function __construct(
        Connection $connection,
        LoggerInterface $logger,
        DefinitionInstanceRegistry $definitionRegistry,
        ReqserCmsElementService $cmsElementService
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
        $this->definitionRegistry = $definitionRegistry;
        $this->cmsElementService = $cmsElementService;
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
     * Get schema information for translatable columns only
     * Returns an associative array with column names as keys and their schema as values
     * Only includes columns that are marked as translatable in the entity definition
     * 
     * @param string $tableName The translation table name
     * @return array<string, array<string, mixed>> Associative array: [columnName => schema]
     * @throws \InvalidArgumentException If table name is invalid
     * @throws \RuntimeException If database query fails
     */
    public function getTranslatableColumnsSchema(string $tableName): array
    {
        // Verify this is a valid translation table
        $tables = $this->getTranslationTables();
        
        if (!in_array($tableName, $tables, true)) {
            throw new \InvalidArgumentException("Table '$tableName' is not a valid translation table");
        }

        // Get translatable field names for this specific table
        $translatableFields = $this->getTranslatableFieldsForTable($tableName);
        $allColumns = $this->getTableFullSchema($tableName);
        
        // Convert allColumns from indexed array to keyed array by columnName
        $columnsByName = [];
        foreach ($allColumns as $columnInfo) {
            $columnsByName[$columnInfo['columnName']] = $columnInfo;
        }
        
        // Build result with only translatable columns
        $schemaResult = [];
        foreach ($translatableFields as $column) {
            if (isset($columnsByName[$column])) {  
                $schemaResult[$column] = $columnsByName[$column];
            }
        }

        return $schemaResult;
    }

    /**
     * Get detailed information about a specific translatable column
     * Checks if the column type is JSON and returns extended details
     * 
     * @param string $tableName The translation table name
     * @param string $columnName The column name to get details for
     * @param array<string, mixed> $columnSchema The schema for this column
     * @return array{columnName: string, schema: array, rowDetails: array}
     */
    public function getTranslationTableRowDetails(string $tableName, string $columnName, array $columnSchema): array
    {
        $rowDetails = [];
        // Check if the column data type is JSON
        $dataType = strtolower($columnSchema['dataType'] ?? '');
        $isJson = $dataType === 'json';

        $rowDetails['isJson'] = $isJson;

        if ($isJson) {
            // If we have JSON, make a distinction for different types
            if ($columnName === 'custom_fields') {
                // Custom fields handling
                $rowDetails['jsonType'] = 'custom_fields';
            } else {
                // Check if this is a CMS element column
                $cmsAnalysis = $this->cmsElementService->analyzeCmsColumnStructure($tableName, $columnName);
                
                if ($cmsAnalysis['isCmsColumn']) {
                    $rowDetails['jsonType'] = 'cms_element';
                    $rowDetails['cmsElementTypes'] = $cmsAnalysis['elementTypes'];
                    $rowDetails['translatableFieldsFound'] = $cmsAnalysis['translatableFieldsFound'];
                    $rowDetails['sampleStructure'] = $cmsAnalysis['sampleStructure'];
                    $rowDetails['availableElementDefinitions'] = $this->cmsElementService->getAllCmsElementDefinitions();
                } else {
                    $rowDetails['jsonType'] = 'other';
                }
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
     * @param string $tableName The translation table name (e.g., 'product_translation')
     * @return array<string> Array of translatable field names
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
     * 
     * @param string $tableName The table name
     * @return array<int, array<string, mixed>> Array of column definitions
     * @throws \RuntimeException If schema query fails
     */
    private function getTableFullSchema(string $tableName): array
    {
        $databaseName = $this->connection->getDatabase();

        // Query to get column information for the table
        $sql = "
            SELECT 
                COLUMN_NAME as columnName,
                DATA_TYPE as dataType,
                COLUMN_TYPE as columnType,
                IS_NULLABLE as isNullable,
                COLUMN_DEFAULT as columnDefault,
                CHARACTER_MAXIMUM_LENGTH as maxLength,
                COLUMN_KEY as columnKey,
                EXTRA as extra,
                COLUMN_COMMENT as comment
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :database
            AND TABLE_NAME = :table
            ORDER BY ORDINAL_POSITION
        ";

        $columns = $this->connection->fetchAllAssociative($sql, [
            'database' => $databaseName,
            'table' => $tableName
        ]);

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

