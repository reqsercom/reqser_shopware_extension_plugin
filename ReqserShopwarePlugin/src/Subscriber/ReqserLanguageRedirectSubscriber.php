<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\HttpFoundation\RequestStack;
use Reqser\Plugin\Service\ReqserLanguageRedirectService;
use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserCustomFieldService;
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
    private LoggerInterface $logger;

    public function __construct(
        RequestStack $requestStack,
        ReqserLanguageRedirectService $languageRedirectService,
        EntityRepository $domainRepository,
        ReqserWebhookService $webhookService,
        ReqserCustomFieldService $customFieldService,
        LoggerInterface $logger
    ) {
        $this->requestStack = $requestStack;
        $this->languageRedirectService = $languageRedirectService;
        $this->domainRepository = $domainRepository;
        $this->webhookService = $webhookService;
        $this->customFieldService = $customFieldService;
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
            if (!$this->redirectService->isAppActive()) {
                return;
            }

            // Extract basic request data
            $request = $event->getRequest();
            $domainId = $request->attributes->get('sw-domain-id');
            $session = $request->getSession();

            // Get sales channel domains and current domain
            $salesChannelDomains = $this->getSalesChannelDomains($event->getSalesChannelContext());
            $currentDomain = $salesChannelDomains->get($domainId);
            
            if (!$currentDomain) {
                return;
            }

            $customFields = $currentDomain->getCustomFields();
            
            // Initialize the service with event context and current domain configuration
            $this->languageRedirectService->initialize($event, $session, $customFields, $request, $currentDomain);

            // Delegate the complex redirect logic to the service
            $this->languageRedirectService->processRedirect(
                $event,
                $currentDomain,
                $salesChannelDomains
            );

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
