<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Cms\DataAbstractionLayer\Field\SlotConfigField;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;

/**
 * Service for detecting special JSON field types in Shopware
 * 
 * This service dynamically identifies CMS element columns by checking Shopware's entity definitions
 * for SlotConfigField types and verifies if columns contain JSON data.
 */
class ReqserJsonFieldDetectionService
{
    private Connection $connection;
    private DefinitionInstanceRegistry $definitionRegistry;
    private ?array $cmsFieldCache = null;

    public function __construct(Connection $connection, DefinitionInstanceRegistry $definitionRegistry)
    {
        $this->connection = $connection;
        $this->definitionRegistry = $definitionRegistry;
    }

    /**
     * Check if a column contains CMS element configuration data
     * 
     * Uses Shopware's entity definitions to dynamically identify CMS slot config fields.
     * This checks if the field is defined as a SlotConfigField in any entity definition.
     * 
     * @param string $tableName The table name
     * @param string $columnName The column name
     * @return bool True if this is a CMS element configuration column
     */
    public function isCmsElementColumn(string $tableName, string $columnName): bool
    {
        // Build cache on first call
        if ($this->cmsFieldCache === null) {
            $this->buildCmsFieldCache();
        }
        
        // Check if this table.column combination is in our cache
        $key = $tableName . '.' . $columnName;
        if (isset($this->cmsFieldCache[$key])) {
            return true;
        }
        
        // Cache miss - try direct lookup for this specific table
        // This handles cases where definitions weren't fully loaded during cache building
        try {
            $definition = $this->definitionRegistry->getByEntityName($tableName);
            if ($definition) {
                $fields = $definition->getFields();
                foreach ($fields as $field) {
                    if ($field->getStorageName() === $columnName && $field instanceof SlotConfigField) {
                        // Found it! Add to cache for future lookups
                        $this->cmsFieldCache[$key] = true;
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Definition not found or error - not a CMS element column
        }
        
        return false;
    }
    
    /**
     * Build cache of all CMS slot config fields from Shopware's entity definitions
     * 
     * Scans all registered entity definitions and identifies fields that are
     * defined as SlotConfigField, which is Shopware's way of marking CMS configurations.
     */
    private function buildCmsFieldCache(): void
    {
        $this->cmsFieldCache = [];
        
        try {
            // Get all registered entity definitions
            $definitions = $this->definitionRegistry->getDefinitions();
            
            foreach ($definitions as $definition) {
                $entityName = $definition->getEntityName();
                $fields = $definition->getFields();
                
                foreach ($fields as $field) {
                    $tableName = $entityName;
                    $columnName = $field->getStorageName();
                    $key = $tableName . '.' . $columnName;
                    
                    // Check if this field is a SlotConfigField (Shopware's CMS field type)
                    if ($field instanceof SlotConfigField) {
                        $this->cmsFieldCache[$key] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // If something goes wrong, fall back to empty cache
            // The direct lookup fallback in isCmsElementColumn will handle individual cases
            $this->cmsFieldCache = [];
        }
    }
    
    /**
     * Check if a column contains JSON data
     * Uses ONLY actual data verification - no hardcoded patterns
     * 
     * @param string $tableName The table name
     * @param string $columnName The column name
     * @param string $type The MySQL column type
     * @return bool True if the column contains JSON data
     */
    public function isJsonColumn(string $tableName, string $columnName, string $type): bool
    {
        // 1. Native JSON type (MySQL 5.7+) - definitive
        if ($type === 'json') {
            return true;
        }
        
        // 2. For text types, verify actual content
        // No assumptions based on column names - check the data itself
        $textTypes = ['text', 'longtext', 'mediumtext', 'tinytext'];
        if (in_array($type, $textTypes)) {
            // Actually check if the data is JSON by sampling
            return $this->verifyJsonContent($tableName, $columnName);
        }
        
        // 3. Not a text type and not JSON type - definitely not JSON
        return false;
    }
    
    /**
     * Verify if a text column actually contains JSON data
     * Samples actual data to determine if it's JSON (no assumptions)
     * 
     * @param string $tableName The table name
     * @param string $columnName The column name
     * @return bool
     */
    private function verifyJsonContent(string $tableName, string $columnName): bool
    {
        try {
            // Sample multiple rows to get a reliable result
            $sql = "SELECT `{$columnName}` FROM `{$tableName}` 
                    WHERE `{$columnName}` IS NOT NULL 
                    AND TRIM(`{$columnName}`) != '' 
                    LIMIT 3";
            
            $samples = $this->connection->fetchFirstColumn($sql);
            
            if (empty($samples)) {
                // No data available - cannot determine
                // Return false (be conservative - assume it's text unless proven otherwise)
                return false;
            }
            
            // Check all samples - if any is valid JSON, column likely contains JSON
            $jsonCount = 0;
            foreach ($samples as $sample) {
                if (empty($sample)) {
                    continue;
                }
                
                // Trim whitespace
                $sample = trim($sample);
                
                // Quick check: JSON starts with { or [
                if (!empty($sample) && ($sample[0] === '{' || $sample[0] === '[')) {
                    // Try to decode as JSON
                    $decoded = json_decode($sample, true);
                    
                    // Count valid JSON
                    if ($decoded !== null && json_last_error() === JSON_ERROR_NONE) {
                        $jsonCount++;
                    }
                }
            }
            
            // If at least one sample is valid JSON, consider it a JSON column
            return $jsonCount > 0;
            
        } catch (\Throwable $e) {
            // On error, be conservative and return false
            // Better to miss a JSON column than falsely identify text as JSON
            return false;
        }
    }
}
