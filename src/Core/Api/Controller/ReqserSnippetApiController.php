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
     * - includeCoreFiles (bool, optional): Include Shopware core and SwagLanguagePack files. Default: false
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/snippets/collect',
        name: 'api.action.reqser.snippets.collect',
        defaults: ['_acl' => ['system:clear:cache']],
        methods: ['POST']
    )]
    public function collectSnippets(Request $request, Context $context): JsonResponse
    {
        try {
            // First check if Reqser App is active
            if (!$this->appService->isAppActive()) {
                $this->logger->warning('Reqser API: Unauthorized access attempt - Reqser App not active', [
                    'file' => __FILE__, 
                    'line' => __LINE__,
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
                    'file' => __FILE__, 
                    'line' => __LINE__,
                ]);
                
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Access denied',
                    'message' => 'This endpoint can only be accessed via the Reqser App integration credentials'
                ], 403);
            }

            // Get request parameters
            $requestData = json_decode($request->getContent(), true) ?? [];
            $includeCoreFiles = (bool) ($requestData['includeCoreFiles'] ?? false);

            $this->logger->info('Reqser API: Starting snippet collection', [
                'includeCoreFiles' => $includeCoreFiles,
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);

            // Collect all snippets from JSON files
            $snippetData = $this->snippetApiService->collectSnippets($includeCoreFiles);

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
        defaults: ['_acl' => ['system:clear:cache']],
        methods: ['GET']
    )]
    public function getStatus(Request $request, Context $context): JsonResponse
    {
        try {
            // Check if Reqser App is active
            $isAppActive = $this->appService->isAppActive();
            
            // Check if request is from Reqser App integration
            $isReqserAppAuthenticated = $this->appService->isRequestFromReqserApp($context);

            // Only allow status check if authenticated via Reqser App
            if (!$isReqserAppAuthenticated) {
                $this->logger->warning('Reqser API: Unauthorized status check attempt - Not authenticated via Reqser App integration', [
                    'file' => __FILE__, 
                    'line' => __LINE__,
                ]);
                
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Access denied',
                    'message' => 'This endpoint can only be accessed via the Reqser App integration credentials'
                ], 403);
            }

            return new JsonResponse([
                'success' => true,
                'status' => [
                    'reqserAppActive' => $isAppActive,
                    'reqserAppAuthenticated' => $isReqserAppAuthenticated,
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
}

