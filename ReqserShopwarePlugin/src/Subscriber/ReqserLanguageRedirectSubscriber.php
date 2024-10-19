<?php
declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Reqser\Plugin\Service\ReqserNotificationService;
use Reqser\Plugin\Service\ReqserWebhookService;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Symfony\Contracts\Cache\CacheInterface;
use Doctrine\DBAL\Connection;

class ReqserLanguageRedirectSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $notificationService;
    private $salesChannelContextFactory;
    private $domainRepository;
    private $cache;
    private Connection $connection;
    private $webhookService;

    public function __construct(
        RequestStack $requestStack, 
        ReqserNotificationService $notificationService, 
        CachedSalesChannelContextFactory $salesChannelContextFactory, 
        EntityRepository $domainRepository,
        CacheInterface $cache,
        Connection $connection,
        ReqserWebhookService $webhookService
        )
    {
        $this->requestStack = $requestStack;
        $this->notificationService = $notificationService;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->domainRepository = $domainRepository;
        $this->cache = $cache;
        $this->connection = $connection;
        $this->webhookService = $webhookService;
    }

    public static function getSubscribedEvents(): array
    {
        // Define the event(s) this subscriber listens to
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender'
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        try{
            if (!$this->cache->hasItem('reqser_app_active')) {
                //Double check if the app is active
                $app_name = "ReqserApp";
                $is_app_active = $this->connection->fetchOne(
                    "SELECT active FROM `app` WHERE name = :app_name",
                    ['app_name' => $app_name]
                );
                if (!$is_app_active) {
                    return;
                }
                $cacheItem = $this->cache->getItem('reqser_app_active');
                $cacheItem->set(true);
                $cacheItem->expiresAfter(86400);
                $this->cache->save($cacheItem);
            }

            $request = $event->getRequest();
            $domainId = $request->attributes->get('sw-domain-id');
            $session = $request->getSession(); // Get the session from the request

            if ($session->get('reqser_redirect_done', false)) {
                return; // Skip redirect if it's already been done
            }
        
            // Retrieve sales channel domains for the current context
            $salesChannelDomains = $this->getSalesChannelDomains($event->getSalesChannelContext(), $domainId);

            if (count($salesChannelDomains) > 0) {
                //Now check if the first result is the current one, if not this domain is not redirectected if accessed 
                if ($salesChannelDomains->first()->getId() != $domainId) {
                    return;
                }

                //Now the current domain needs to have status true, if not we will not redirect if a user has accessed this domain directly
                $customFields = $salesChannelDomains->first()->getCustomFields();
                if (!isset($customFields['ReqserRedirect']['redirectFrom']) || $customFields['ReqserRedirect']['redirectFrom'] !== true) {
                    return;
                }

                $browserLanguages = explode(',', (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''));
                $region_code_exist = false;
        
                foreach ($browserLanguages as $browserLanguage) {
                    $languageCode = explode(';', $browserLanguage)[0]; // Get the part before ';'
                    if (!$region_code_exist && strpos($languageCode, '-') !== false) {
                        $region_code_exist = true;
                    }
                    $preferred_browser_language = strtolower(trim($languageCode)); // Normalize to lowercase and trim spaces
                    $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $domainId);

                    if (isset($customFields['ReqserRedirect']['onlyRedirectDefaultLanguage']) && $customFields['ReqserRedirect']['onlyRedirectDefaultLanguage'] === true) {
                        break; // Break the loop if onlyRedirectDefaultLanguage is enabled
                    }
                }
        
                // Second pass: If matchOnlyLanguage is enabled, check without region code
                if ($region_code_exist && isset($customFields['ReqserRedirect']['redirectWithoutCountryCode']) && $customFields['ReqserRedirect']['redirectWithoutCountryCode'] === true) {
                    foreach ($browserLanguages as $browserLanguage) {
                        $languageCode = explode(';', $browserLanguage)[0]; // Get the part before ';'
                        $preferred_browser_language = strtolower(trim(explode('-', $languageCode)[0])); // Trim and get only the language code
                        $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $domainId);
                        if (isset($customFields['ReqserRedirect']['onlyRedirectDefaultLanguage']) && $customFields['ReqserRedirect']['onlyRedirectDefaultLanguage'] === true) {
                            break; // Break the loop if onlyRedirectDefaultLanguage is enabled
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log the error message and continue with the next directory
            if (method_exists($this->logger, 'error')) {
                $this->logger->error('Reqser Plugin Error onStorefrontRender', [
                    'message' => $e->getMessage(),
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
    
    private function handleLanguageRedirect(string $preferred_browser_language, SalesChannelDomainCollection $salesChannelDomains, string $domainId): void
    {
        foreach ($salesChannelDomains as $salesChannelDomain) {
            $customFields = $salesChannelDomain->getCustomFields();
    
            // Check if ReqserRedirect exists and has a languageRedirect array
            if (isset($customFields['ReqserRedirect']['languageRedirect']) && is_array($customFields['ReqserRedirect']['languageRedirect'])) {
                if (in_array($preferred_browser_language, $customFields['ReqserRedirect']['languageRedirect'])) {
                    
                    if ($domainId != $salesChannelDomain->getId()) {
                        $this->requestStack->getSession()->set('reqser_redirect_done', true);
                        $response = new RedirectResponse($salesChannelDomain->getUrl());
                        $response->send();
                        exit; // Exit after redirecting
                    }
                }
            }
        }
    }

    private function getSalesChannelDomains(SalesChannelContext $context, string $domainId)
    {
        // Retrieve the full collection
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannel()->getId()));

        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.ReqserRedirect', null)
        ]));

        // Get the collection from the repository
        $salesChannelDomains = $this->domainRepository->search($criteria, $context->getContext())->getEntities();

        // Manually prioritize the current domain by reordering the collection
        $sortedDomains = new SalesChannelDomainCollection();
        $currentDomain = $salesChannelDomains->get($domainId);

        if ($currentDomain) {
            // Add the current domain to the front
            $sortedDomains->add($currentDomain);

            // Add the remaining domains after the current domain
            foreach ($salesChannelDomains as $domain) {
                if ($domain->getId() !== $domainId) {
                    $sortedDomains->add($domain);
                }
            }
        } else {
            // If current domain is not found, return the original collection
            return $salesChannelDomains;
        }

        return $sortedDomains;
    }

}
