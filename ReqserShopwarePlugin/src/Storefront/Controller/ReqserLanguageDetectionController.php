<?php declare(strict_types=1);

namespace Reqser\Plugin\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Reqser\Plugin\Service\ReqserLanguageRedirectService;
use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserCustomFieldService;
use Reqser\Plugin\Service\ReqserAppService;
use Reqser\Plugin\Service\ReqserSessionService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ReqserLanguageDetectionController extends StorefrontController
{
    private $languageRedirectService;
    private $domainRepository;
    private $webhookService;
    private $customFieldService;
    private $appService;
    private $cache;
    private LoggerInterface $logger;

    public function __construct(
        ReqserLanguageRedirectService $languageRedirectService,
        EntityRepository $domainRepository,
        ReqserWebhookService $webhookService,
        ReqserCustomFieldService $customFieldService,
        ReqserAppService $appService,
        CacheInterface $cache,
        LoggerInterface $logger
    ) {
        $this->languageRedirectService = $languageRedirectService;
        $this->domainRepository = $domainRepository;
        $this->webhookService = $webhookService;
        $this->customFieldService = $customFieldService;
        $this->appService = $appService;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    #[Route(path: '/reqser/language-detection/check', name: 'frontend.reqser.language_detection.check', defaults: ['XmlHttpRequest' => true], methods: ['GET'])]
    public function checkLanguage(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        
        try {
            // Check if the app is active (includes environment check)
            if (!$this->appService->isAppActive()) {
                return $this->createJsonResponse(false, 'app_inactive');
            }

            // Extract basic request data
            $domainId = $request->attributes->get('sw-domain-id');
            if (!$domainId) {
                return $this->createJsonResponse(false, 'no_domain_id');
            }

            $salesChannelId = $request->attributes->get('sw-sales-channel-id');
            if (!$salesChannelId) {
                return $this->createJsonResponse(false, 'no_sales_channel_id');
            }
            
            //If Basic Checks are ok we can continue to process
            return $this->processRequest($request, $domainId, $salesChannelId);

        } catch (\Throwable $e) {
            return $this->createJsonResponse(false, 'internal_error', [
                'error' => $e->getMessage(),
                'errorFile' => $e->getFile(),
                'errorLine' => $e->getLine()
            ]);
        }
    }

    /**
     * Process the language detection request
     */
    private function processRequest(Request $request, string $domainId, string $salesChannelId): JsonResponse
    {
        // Get sales channel domains and current domain using cached method
        $salesChannelDomains = $this->languageRedirectService->getSalesChannelDomainsById($salesChannelId);
        if ($salesChannelDomains->count() <= 1) {
            return $this->createJsonResponse(false, 'only_one_domain_available');
        }

        $currentDomain = $salesChannelDomains->get($domainId);
        if (!$currentDomain) {
            return $this->createJsonResponse(false, 'domain_not_found');
        }
        
        $session = ReqserSessionService::getSessionWithFallback($request, $this->container->get('request_stack'));
        if (!$session) {
            return $this->createJsonResponse(false, 'session_not_found');
        }

        $customFields = $currentDomain->getCustomFields();
        if (!$customFields) {
            return $this->createJsonResponse(false, 'custom_fields_not_found');
        }
        $redirectConfig = $this->customFieldService->getRedirectConfiguration($customFields);

        $additionalData = [];
        if ($redirectConfig['debugMode']) {
            $additionalData['customFieldsConfig'] = $redirectConfig;
            $additionalData['primaryBrowserLanguage'] = $this->languageRedirectService->getPrimaryBrowserLanguage();
            $additionalData['domainUrl'] = $currentDomain->getUrl();
            $additionalData['currentOrignalURL'] = $this->languageRedirectService->getOriginalPageUrl();
            $additionalData['isDomainValidForRedirectFrom'] = $this->languageRedirectService->isDomainValidForRedirectFrom();
        }

        // Initialize the service with the retrieved data
        $this->languageRedirectService->initialize($session, $redirectConfig, $request, $currentDomain, $salesChannelDomains);
        
        //Now we call each Method
        if (!$this->languageRedirectService->shouldProcessRedirect()) {
            return $this->createJsonResponse(true, 'redirect_not_needed', $additionalData);
        }

        //Check for Manual Language Switch and cancel if necessary
        if (!$this->languageRedirectService->shouldProcessRedirectBasedOnManualLanguageSwitch()) {
            return $this->createJsonResponse(true, 'redirect_blocked_by_manual_language_switch', $additionalData);
        }

        //Check for Session Redirect and cancel if necessary
        if (!$this->languageRedirectService->shouldProcessRedirectBasedOnSessionData()) {
           
            return $this->createJsonResponse(true, 'redirect_blocked_by_session_data', $additionalData);
        }

        // Get the redirect URL from the service
        $redirectUrl = $this->languageRedirectService->retrieveNewDomainToRedirectTo();
        
        if ($redirectUrl) {
            //Adapt Session Data
            $this->languageRedirectService->updateSessionData();
            // Found a matching domain to redirect to
            return $this->createJsonResponse(true, 'language_mismatch', [
                array_merge($additionalData, [
                    'shouldRedirect' => true,
                    'redirectUrl' => $redirectUrl,
                ])
            ]);
        } else {
            return $this->createJsonResponse(true, 'no_matching_language_domain_found', [
                array_merge($additionalData, [
                    'shouldRedirect' => false,
                ])
            ]);
        }
    }

    /**
     * Create a standardized JSON response
     */
    private function createJsonResponse(bool $success, ?string $reason = null, array $additionalData = []): JsonResponse
    {
        $data = [
            'success' => $success,
        ];
        
        if ($reason !== null) {
            $data['reason'] = $reason;
        }
        
        return new JsonResponse(array_merge($data, $additionalData));
    }

    
}
