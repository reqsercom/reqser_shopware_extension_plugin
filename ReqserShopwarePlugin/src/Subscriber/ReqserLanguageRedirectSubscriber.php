<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Reqser\Plugin\Service\ReqserLanguageRedirectService;
use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserCustomFieldService;
use Reqser\Plugin\Service\ReqserAppService;
use Reqser\Plugin\Service\ReqserSessionService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Psr\Log\LoggerInterface;

class ReqserLanguageRedirectSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $languageRedirectService;
    private $domainRepository;
    private $webhookService;
    private $customFieldService;
    private $appService;
    private LoggerInterface $logger;

    public function __construct(
        RequestStack $requestStack, 
        ReqserLanguageRedirectService $languageRedirectService,
        EntityRepository $domainRepository,
        ReqserWebhookService $webhookService,
        ReqserCustomFieldService $customFieldService,
        ReqserAppService $appService,
        LoggerInterface $logger
    ) {
        $this->requestStack = $requestStack;
        $this->languageRedirectService = $languageRedirectService;
        $this->domainRepository = $domainRepository;
        $this->webhookService = $webhookService;
        $this->customFieldService = $customFieldService;
        $this->appService = $appService;
        $this->logger = $logger;
    }

    /**
     * Get the events this subscriber listens to
     */
    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender'
        ];
    }

    /**
     * Handle storefront render events for language-based redirects
     * This is now a thin event handler that delegates to the service
     */
    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        
        try {
            // Check if the app is active
            if (!$this->appService->isAppActive()) {
                return; 
            }

            // Extract basic request data
            $request = $event->getRequest();
            
            // Skip redirect logic for ESI fragments and internal routes
            if (!$this->routeIsAllowedForRedirect($request)) {
                return;
            }
            
            $domainId = $request->attributes->get('sw-domain-id');
            $session = ReqserSessionService::getSessionWithFallback($request, $this->requestStack);

            // Get sales channel domains and current domain
            $salesChannelDomains = $this->getSalesChannelDomains($event->getSalesChannelContext());
            $currentDomain = $salesChannelDomains->get($domainId);
            
            if (!$currentDomain) {
                return;
            }

            $customFields = $currentDomain->getCustomFields();
            
            // Initialize the service with event context and current domain configuration
            $this->languageRedirectService->initialize($event, $session, $customFields, $request, $currentDomain, $salesChannelDomains);

            // Delegate the complex redirect logic to the service
            $this->languageRedirectService->processRedirect($currentDomain);
            
        } catch (\Throwable $e) {
            if (method_exists($this->logger, 'error')) {
                $this->logger->error('Reqser Plugin Error onStorefrontRender', [
                    'message' => $e->getMessage(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ]);
            }
            
            $this->webhookService->sendErrorToWebhook([
                'type' => 'error',
                'function' => 'onStorefrontRender',
                'message' => $e->getMessage() ?? 'unknown',
                'trace' => $e->getTraceAsString() ?? 'unknown',
                'timestamp' => date('Y-m-d H:i:s'),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
        }
    }

    /**
     * Check if redirect logic should be skipped for this route
     */
    private function routeIsAllowedForRedirect($request): bool
    {
        $routeName = $request->attributes->get('_route');
        
        // First check: Only process frontend routes
        if (!$routeName) {
            return false;
        }
        
        // Second check: Only process GET requests
        if (!$request->isMethod('GET')) {
            return false;
        }
        
        // Third check: Only allow specific main page patterns for redirect processing
        if (strpos($routeName, 'frontend.home.') !== 0 &&
            strpos($routeName, 'frontend.navigation.') !== 0 &&
            strpos($routeName, 'frontend.detail.') !== 0 &&
            strpos($routeName, 'frontend.cms.') !== 0) {
            return false; 
        }
        
        // Route is allowed so we can proceed
        return true;
    }

    /**
     * Retrieve sales channel domains with ReqserRedirect custom fields
     */
    private function getSalesChannelDomains($context, ?bool $jumpSalesChannels = null)
    {
        $criteria = new Criteria();
        
        // Ensure we're only retrieving domains that have the 'ReqserRedirect' custom field set
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.ReqserRedirect', null)
        ]));
        
        // If cross-sales channel jumping is disabled, filter to current sales channel only
        if ($jumpSalesChannels !== true) {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannel()->getId()));
        }
    
        // Get the collection from the repository
        return $this->domainRepository->search($criteria, $context->getContext())->getEntities();
    }


}
