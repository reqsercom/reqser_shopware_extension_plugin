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

    public function __construct(
        ReqserAppService $appService,
        LoggerInterface $logger
    ) {
        $this->appService = $appService;
        $this->logger = $logger;
    }

    /**
     * Validate authentication for API requests
     * Checks both localhost and Reqser App integration authentication
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse|null Returns error response if validation fails, null if validation passes
     */
    public function validateAuthentication(Request $request, Context $context): ?JsonResponse
    {
        // Check if request is from localhost (for development testing only)
        if ($this->isLocalhostRequest($request)) {
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

