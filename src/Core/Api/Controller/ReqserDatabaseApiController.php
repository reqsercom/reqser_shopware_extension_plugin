<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Service\ReqserAppService;
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
    private ReqserAppService $appService;
    private LoggerInterface $logger;

    public function __construct(
        ReqserDatabaseService $databaseService,
        ReqserAppService $appService,
        LoggerInterface $logger
    ) {
        $this->databaseService = $databaseService;
        $this->appService = $appService;
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
            $authResponse = $this->validateAuthentication($request, $context);
            if ($authResponse !== null) {
                return $authResponse; // Return error response if validation failed
            }

            // Get translation tables from database
            $result = $this->databaseService->getTranslationTables();

            if (!$result['success']) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $result['error'] ?? 'Unknown error',
                    'message' => $result['message'] ?? ''
                ], 500);
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'tables' => $result['tables'],
                    'count' => $result['count'],
                    'database' => $result['database']
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
     * Validate authentication for API requests
     * Checks both localhost and Reqser App integration authentication
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse|null Returns error response if validation fails, null if validation passes
     */
    private function validateAuthentication(Request $request, Context $context): ?JsonResponse
    {
        // Check if request is from localhost (for development testing only)
        $isLocalhost = $this->isLocalhostRequest($request);
        
        if ($isLocalhost) {
            return null; // Allow request
        }
        
        // For production: check if Reqser App is active (skip cache for critical operations)
        if (!$this->appService->isAppActive(skipCache: true)) {
            $this->logger->warning('Reqser API: Unauthorized access attempt - Reqser App not active', [
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'file' => __FILE__,
                'line' => __LINE__
            ]);
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Reqser App is not active',
                'message' => 'The Reqser App must be installed and active to use this endpoint'
            ], 403);
        }

        // Verify request is authenticated via Reqser App integration
        if (!$this->appService->isRequestFromReqserApp($context)) {
            $this->logger->warning('Reqser API: Unauthorized access attempt - Not authenticated via Reqser App integration', [
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'file' => __FILE__,
                'line' => __LINE__
            ]);
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Access denied',
                'message' => 'This endpoint can only be accessed via the Reqser App integration credentials'
            ], 403);
        }
        
        return null; // Validation passed
    }

    /**
     * Check if request is from localhost (for development testing only)
     * 
     * @param Request $request
     * @return bool
     */
    private function isLocalhostRequest(Request $request): bool
    {
        $clientIp = $request->getClientIp();
        $host = $request->getHost();
        
        // Check for localhost IP addresses
        $localhostIps = ['127.0.0.1', '::1', 'localhost'];
        
        // Check if client IP is localhost
        if (in_array($clientIp, $localhostIps, true)) {
            return true;
        }
        
        // Check if host is localhost
        if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return true;
        }
        
        return false;
    }
}

