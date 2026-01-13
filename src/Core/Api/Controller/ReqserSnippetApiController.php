<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Service\ReqserApiAuthService;
use Reqser\Plugin\Service\ReqserSnippetApiService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API Controller for Reqser Snippet Collection
 * Accessible only via authenticated API requests
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReqserSnippetApiController extends AbstractController
{
    private ReqserSnippetApiService $snippetApiService;
    private ReqserApiAuthService $authService;
    private LoggerInterface $logger;

    public function __construct(
        ReqserSnippetApiService $snippetApiService,
        ReqserApiAuthService $authService,
        LoggerInterface $logger
    ) {
        $this->snippetApiService = $snippetApiService;
        $this->authService = $authService;
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
     * - file_path (string, optional): Specific path to collect from (prevents scanning all folders). Default: null
     * - only_collect_path (bool, optional): Only return file paths without snippet data. Default: false
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
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse; // Return error response if validation failed
            }

            // Get request parameters
            $requestData = json_decode($request->getContent(), true) ?? [];
            $snippetSetId = $requestData['snippetSetId'] ?? null;
            $includeCoreFiles = (bool) ($requestData['includeCoreFiles'] ?? false);
            $filePath = $requestData['file_path'] ?? null;
            $onlyCollectPath = (bool) ($requestData['only_collect_path'] ?? false);

            // Validate required parameters
            if (!$snippetSetId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameter',
                    'message' => 'The parameter "snippetSetId" is required'
                ], 400);
            }

            // Collect all snippets from JSON files
            $snippetData = $this->snippetApiService->collectSnippets(
                $snippetSetId, 
                $includeCoreFiles,
                $filePath,
                $onlyCollectPath
            );

            if (isset($snippetData['error'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $snippetData['error'],
                    'message' => $snippetData['message'] ?? ''
                ], 400);
            }

            return new JsonResponse([
                'success' => true,
                'data' => $snippetData,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Throwable $e) {
            // Return error in API response without creating Shopware log entries
            // This prevents log pollution and handles all errors gracefully
            return new JsonResponse([
                'success' => false,
                'error' => 'Error collecting snippets',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }
}
