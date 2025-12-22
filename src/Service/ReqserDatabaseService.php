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

    public function __construct(
        Connection $connection,
        LoggerInterface $logger,
        DefinitionInstanceRegistry $definitionRegistry
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
        $this->definitionRegistry = $definitionRegistry;
    }

    /**
     * Get all database tables ending with _translation
     * 
     * @return array{success: bool, tables?: array, count?: int, error?: string, message?: string}
     */
    public function getTranslationTables(): array
    {
        try {
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

            return [
                'success' => true,
                'tables' => $tables,
                'count' => count($tables),
                'database' => $databaseName
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Database query failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get schema information for a specific translation table
     * Returns standard MySQL column structure for the table
     * Also identifies which fields appear in the Admin API "translated" object
     * 
     * @param string $tableName The translation table name to get schema for
     * @return array{success: bool, table?: array, database?: string, error?: string, message?: string}
     */
    public function getTranslationTableSchema(string $tableName): array
    {
        try {
            // Security check: Only allow tables ending with _translation
            if (!str_ends_with($tableName, '_translation')) {
                return [
                    'success' => false,
                    'error' => 'Invalid table name',
                    'message' => "Table name must end with '_translation'"
                ];
            }

            $databaseName = $this->connection->getDatabase();

            // Verify this is a valid translation table
            $tablesResult = $this->getTranslationTables();
            if (!$tablesResult['success']) {
                return $tablesResult;
            }

            if (!in_array($tableName, $tablesResult['tables'], true)) {
                return [
                    'success' => false,
                    'error' => 'Invalid table name',
                    'message' => "Table '$tableName' is not a valid translation table"
                ];
            }

            // Get translatable fields from entity definitions
            $translatableFieldsMap = $this->getTranslatableFieldsFromDefinitions();
            $translatableFields = $translatableFieldsMap[$tableName] ?? [];

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

            // Filter to only include columns that are in the translatable fields (in Admin API "translated" object)
            $translatableColumns = [];
            foreach ($columns as $column) {
                $columnName = $column['columnName'];
                
                // Only include columns that are in the translatable fields
                if (in_array($columnName, $translatableFields, true)) {
                    $translatableColumns[] = $column;
                }
            }

            return [
                'success' => true,
                'table' => [
                    'tableName' => $tableName,
                    'columns' => $translatableColumns,
                    'translatableFieldCount' => count($translatableColumns),
                    'totalColumns' => count($columns)
                ],
                'database' => $databaseName
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Database schema query failed',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Get translatable fields from Shopware entity definitions
     * Returns a map of translation table names to their translatable field names
     * 
     * @return array<string, array<string>>
     */
    private function getTranslatableFieldsFromDefinitions(): array
    {
        $translatableFieldsMap = [];

        try {
            // Get all entity definitions
            $definitions = $this->definitionRegistry->getDefinitions();

            foreach ($definitions as $definition) {
                /** @var EntityDefinition $definition */
                $entityName = $definition->getEntityName();
                
                // Get the translation definition if it exists
                try {
                    $translationDefinition = $definition->getTranslationDefinition();
                    
                    if ($translationDefinition !== null) {
                        $translationTableName = $translationDefinition->getEntityName();
                        $translatableFields = [];

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

                        if (!empty($translatableFields)) {
                            $translatableFieldsMap[$translationTableName] = $translatableFields;
                        }
                    }
                } catch (\Throwable $e) {
                    // Skip entities without translation definitions
                    continue;
                }
            }
        } catch (\Throwable $e) {
            // If we can't get definitions, return empty map
            // The schema will still work, just without translatable field info
        }

        return $translatableFieldsMap;
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

