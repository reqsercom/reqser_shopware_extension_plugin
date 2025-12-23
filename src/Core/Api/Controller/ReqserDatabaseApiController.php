<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Service\ReqserApiAuthService;
use Reqser\Plugin\Service\ReqserDatabaseService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin API Controller for Reqser Database Operations
 * Accessible only via authenticated API requests
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReqserDatabaseApiController extends AbstractController
{
    private ReqserDatabaseService $databaseService;
    private ReqserApiAuthService $authService;
    private LoggerInterface $logger;

    public function __construct(
        ReqserDatabaseService $databaseService,
        ReqserApiAuthService $authService,
        LoggerInterface $logger
    ) {
        $this->databaseService = $databaseService;
        $this->authService = $authService;
        $this->logger = $logger;
    }

    /**
     * API endpoint to get all database tables ending with _translation
     * 
     * Requires:
     * - Request MUST be authenticated via the Reqser App's integration credentials
     * - Reqser App must be active
     * - GET method only
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/database/translation-tables',
        name: 'api.action.reqser.database.translation_tables',
        methods: ['GET']
    )]
    public function getTranslationTables(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse; // Return error response if validation failed
            }

            // Get translation tables from database
            $tables = $this->databaseService->getTranslationTables();

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'tables' => $tables,
                    'count' => count($tables)
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Throwable $e) {
            // Return error in API response without creating Shopware log entries
            // This prevents log pollution and handles all errors gracefully
            return new JsonResponse([
                'success' => false,
                'error' => 'Error retrieving translation tables',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * API endpoint to get schema information for a specific translation table
     * Returns column details including data types to identify translatable fields
     * 
     * Requires:
     * - Request MUST be authenticated via the Reqser App's integration credentials
     * - Reqser App must be active
     * - GET method only
     * - tableName must end with '_translation' (security requirement)
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/database/translation-tables/{tableName}/{row}',
        name: 'api.action.reqser.database.translation_tables_schema',
        methods: ['GET'],
        defaults: ['row' => null]
    )]
    public function getTranslationTableSchema(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse; // Return error response if validation failed
            }

            // Get table name from route parameter
            $tableName = $request->attributes->get('tableName');

            if (empty($tableName)) {
                throw new \InvalidArgumentException("Table name is required");
            }

            // Get translatable columns schema
            $tableSchema = $this->databaseService->getTranslatableColumnsSchema($tableName);
            
            // Get optional row identifier from route
            $row = $request->attributes->get('row');

            if (!empty($row)) {
                if (isset($tableSchema[$row])) {
                    //lest get extended detals for this row
                    $rowData = $this->databaseService->getTranslationTableRowDetails($tableName, $row, $tableSchema[$row]);
                    return new JsonResponse([
                        'success' => true,
                        'data' => [
                            'tableName' => $tableName,
                            'row' => $row,
                            'rowData' => $rowData
                        ]
                    ]);
                } else {
                    throw new \InvalidArgumentException("Row identifier '$row' not found in table schema");
                }
            } else  {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'tableName' => $tableName,
                        'schema' => $tableSchema
                    ]
                ]);
            }
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid parameter',
                'message' => $e->getMessage()
            ], 400);
        } catch (\RuntimeException $e) {
            // Check if it's a "not found" error
            if (str_contains($e->getMessage(), 'No row found')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Row not found',
                    'message' => $e->getMessage()
                ], 404);
            }
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Database error',
                'message' => $e->getMessage()
            ], 500);
        } catch (\Throwable $e) {
            // Return error in API response without creating Shopware log entries
            return new JsonResponse([
                'success' => false,
                'error' => 'Error retrieving translation table schema',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}
