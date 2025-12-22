<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service for database-related operations
 */
class ReqserDatabaseService
{
    private Connection $connection;
    private LoggerInterface $logger;

    public function __construct(
        Connection $connection,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
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

            $this->logger->info('Successfully retrieved translation tables', [
                'count' => count($tables),
                'database' => $databaseName,
                'file' => __FILE__,
                'line' => __LINE__
            ]);

            return [
                'success' => true,
                'tables' => $tables,
                'count' => count($tables),
                'database' => $databaseName
            ];

        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve translation tables: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => __FILE__,
                'line' => __LINE__
            ]);

            return [
                'success' => false,
                'error' => 'Database query failed',
                'message' => $e->getMessage()
            ];
        }
    }
}

