<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Service\ReqserAppService;
use Reqser\Plugin\Service\ReqserSnippetApiService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin API Controller for Reqser Snippet Collection
 * Accessible only via authenticated API requests
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReqserSnippetApiController extends AbstractController
{
    private ReqserSnippetApiService $snippetApiService;
    private ReqserAppService $appService;
    private LoggerInterface $logger;

    public function __construct(
        ReqserSnippetApiService $snippetApiService,
        ReqserAppService $appService,
        LoggerInterface $logger
    ) {
        $this->snippetApiService = $snippetApiService;
        $this->appService = $appService;
        $this->logger = $logger;
    }

    /**
     * API endpoint to collect all snippet data from JSON files
     * 
     * Requires:
     * - Request MUST be authenticated via the Reqser App's integration credentials
     * - Reqser App must be active
     * - POST method only
     * 
     * Request Body Parameters:
     * - snippetSetId (string, required): The snippet set ID to collect snippets for
     * - includeCoreFiles (bool, optional): Include Shopware core and SwagLanguagePack files. Default: false
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/snippets/collect',
        name: 'api.action.reqser.snippets.collect',
        methods: ['POST']
    )]
    public function collectSnippets(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->validateAuthentication($request, $context);
            if ($authResponse !== null) {
                return $authResponse; // Return error response if validation failed
            }

            // Get request parameters
            $requestData = json_decode($request->getContent(), true) ?? [];
            $snippetSetId = $requestData['snippetSetId'] ?? null;
            $includeCoreFiles = (bool) ($requestData['includeCoreFiles'] ?? false);

            // Validate required parameters
            if (!$snippetSetId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameter',
                    'message' => 'The parameter "snippetSetId" is required'
                ], 400);
            }

            $this->logger->info('Reqser API: Starting snippet collection', [
                'snippetSetId' => $snippetSetId,
                'includeCoreFiles' => $includeCoreFiles,
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);

            // Collect all snippets from JSON files
            $snippetData = $this->snippetApiService->collectSnippets($snippetSetId, $includeCoreFiles);

            $this->logger->info('Reqser API: Snippet collection completed', [
                'totalFiles' => $snippetData['stats']['totalFiles'] ?? 0,
                'totalSnippets' => $snippetData['stats']['totalSnippets'] ?? 0,
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);

            return new JsonResponse([
                'success' => true,
                'data' => $snippetData,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Reqser API: Error collecting snippets', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * API endpoint to get snippet collection status/info
     * Lightweight endpoint to check configuration without collecting all snippets
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/snippets/status',
        name: 'api.action.reqser.snippets.status',
        methods: ['GET']
    )]
    public function getStatus(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->validateAuthentication($request, $context);
            if ($authResponse !== null) {
                return $authResponse; // Return error response if validation failed
            }
            
            // Check statuses for response
            $isLocalhost = $this->isLocalhostRequest($request);
            $isAppActive = $this->appService->isAppActive();
            $isReqserAppAuthenticated = $isLocalhost ? true : $this->appService->isRequestFromReqserApp($context);

            return new JsonResponse([
                'success' => true,
                'status' => [
                    'reqserAppActive' => $isAppActive,
                    'reqserAppAuthenticated' => $isReqserAppAuthenticated,
                    'isLocalhost' => $isLocalhost,
                    'apiAvailable' => true,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Reqser API: Error getting status', [
                'error' => $e->getMessage(),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage()
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
            $this->logger->info('Reqser API: Localhost request - authentication checks bypassed', [
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
            ]);
            return null; // Allow request
        }
        
        // For production: check if Reqser App is active
        if (!$this->appService->isAppActive()) {
            $this->logger->warning('Reqser API: Unauthorized access attempt - Reqser App not active', [
                'endpoint' => $request->getPathInfo(),
                'method' => $request->getMethod(),
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
            ]);
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Access denied',
                'message' => 'This endpoint can only be accessed via the Reqser App integration credentials'
            ], 403);
        }
        
        $this->logger->info('Reqser API: Authentication successful', [
            'endpoint' => $request->getPathInfo(),
            'method' => $request->getMethod(),
        ]);
        
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

