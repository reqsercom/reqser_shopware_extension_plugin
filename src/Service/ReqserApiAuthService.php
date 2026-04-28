<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service for API authentication and authorization
 */
class ReqserApiAuthService
{
    private ReqserAppService $appService;
    private LoggerInterface $logger;

    /**
     * @param ReqserAppService $appService
     * @param LoggerInterface $logger
     */
    public function __construct(
        ReqserAppService $appService,
        LoggerInterface $logger
    ) {
        $this->appService = $appService;
        $this->logger = $logger;
    }

    /**
     * Validate authentication for API requests via Reqser App integration.
     *
     * @param Request $request
     * @param Context $context
     * @return JsonResponse|bool
     */
    public function validateAuthentication(Request $request, Context $context): JsonResponse|bool
    {
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
        
        return true;
    }
}

