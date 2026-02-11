<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Service\ReqserApiAuthService;
use Reqser\Plugin\Service\ReqserCmsRenderService;
use Reqser\Plugin\Service\ReqserCmsTwigFileService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin API Controller for Reqser CMS Operations
 * Accessible only via authenticated API requests
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReqserCmsApiController extends AbstractController
{
    private ReqserCmsTwigFileService $cmsTwigFileService;
    private ReqserCmsRenderService $cmsRenderService;
    private ReqserApiAuthService $authService;
    private LoggerInterface $logger;

    public function __construct(
        ReqserCmsTwigFileService $cmsTwigFileService,
        ReqserCmsRenderService $cmsRenderService,
        ReqserApiAuthService $authService,
        LoggerInterface $logger
    ) {
        $this->cmsTwigFileService = $cmsTwigFileService;
        $this->cmsRenderService = $cmsRenderService;
        $this->authService = $authService;
        $this->logger = $logger;
    }

    /**
     * API endpoint to get all CMS element Twig template files
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
        path: '/api/_action/reqser/cms/twig-files',
        name: 'api.action.reqser.cms.twig_files',
        methods: ['GET']
    )]
    public function getTwigFiles(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse; // Return error response if validation failed
            }

            // Get all CMS element template files
            $twigFiles = $this->cmsTwigFileService->getAllCmsElementTwigFiles();

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'twigFiles' => $twigFiles
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Throwable $e) {
            // Return error in API response without creating Shopware log entries
            return new JsonResponse([
                'success' => false,
                'error' => 'Error retrieving CMS Twig files',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * API endpoint to render a CMS element
     * 
     * Requires:
     * - Request MUST be authenticated via the Reqser App's integration credentials
     * - Reqser App must be active
     * - POST method only
     * - JSON body with: type (string), config (object or JSON string)
     * 
     * Example 1 (config as object):
     * {
     *   "type": "text",
     *   "config": {
     *     "content": {"value": "<h2>Hello</h2>", "source": "static"},
     *     "verticalAlign": {"value": null, "source": "static"}
     *   }
     * }
     * 
     * Example 2 (config as JSON string from database):
     * {
     *   "type": "text",
     *   "config": "{\"content\": {\"value\": \"<h2>Hello</h2>\", \"source\": \"static\"}}"
     * }
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/cms/render-element',
        name: 'api.action.reqser.cms.render_element',
        methods: ['POST']
    )]
    public function renderElement(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse; // Return error response if validation failed
            }

            // Parse request body
            $data = json_decode($request->getContent(), true);
            
            if (!is_array($data)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid JSON in request body'
                ], 400);
            }

            // Validate required parameters
            $type = $data['type'] ?? null;
            $config = $data['config'] ?? null;

            if (empty($type)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameter: type (e.g., "text", "image", "html")'
                ], 400);
            }

            if (empty($config)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameter: config'
                ], 400);
            }

            // If config is a JSON string (as stored in database), decode it
            if (is_string($config)) {
                $decodedConfig = json_decode($config, true);
                if ($decodedConfig === null && json_last_error() !== JSON_ERROR_NONE) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Invalid JSON in config parameter: ' . json_last_error_msg()
                    ], 400);
                }
                $config = $decodedConfig;
            }

            // Ensure config is now an array
            if (!is_array($config)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid config parameter: must be an object/array or valid JSON string'
                ], 400);
            }

            // Render the CMS element
            $renderedHtml = $this->cmsRenderService->renderCmsElement($type, $config);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'html' => $renderedHtml  // Base64 encoded HTML
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Throwable $e) {
            // Return error in API response
            return new JsonResponse([
                'success' => false,
                'error' => 'Error rendering CMS element',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}

