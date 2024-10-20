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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Symfony\Contracts\Cache\CacheInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;


class ReqserLanguageRedirectSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $notificationService;
    private $salesChannelContextFactory;
    private $domainRepository;
    private $cache;
    private Connection $connection;
    private $webhookService;
    private LoggerInterface $logger;

    public function __construct(
        RequestStack $requestStack, 
        ReqserNotificationService $notificationService, 
        CachedSalesChannelContextFactory $salesChannelContextFactory, 
        EntityRepository $domainRepository,
        CacheInterface $cache,
        Connection $connection,
        ReqserWebhookService $webhookService,
        LoggerInterface $logger
        )
    {
        $this->requestStack = $requestStack;
        $this->notificationService = $notificationService;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->domainRepository = $domainRepository;
        $this->cache = $cache;
        $this->connection = $connection;
        $this->webhookService = $webhookService;
        $this->logger = $logger;
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

            // Retrieve sales channel domains for the current context
            $salesChannelDomains = $this->getSalesChannelDomains($event->getSalesChannelContext());

            //Has to be bigger than one since the first entry should be the current one for a redirect
            if ($salesChannelDomains->count() > 1) {
                //Now check if the current domain exist, if not we can finish the function and do nothing
                $currentDomain = $salesChannelDomains->get($domainId);
                if (!$currentDomain) {
                    return;
                }

                $customFields = $currentDomain->getCustomFields();
                if (!isset($customFields['ReqserRedirect']['redirectFrom']) || $customFields['ReqserRedirect']['redirectFrom'] !== true) {
                    return;
                }

                if ($session->get('reqser_redirect_done', false)) {
                    if (!isset($customFields['ReqserRedirect']['debugMode']) || $customFields['ReqserRedirect']['debugMode'] !== true) return;
                } else {
                    $this->requestStack->getSession()->set('reqser_redirect_done', true);
                }

                //If the current sales channel is allowing to jump Sales Channels on Redirect we need also retrieve the other domains
                $jumpSalesChannels = false;
                if (isset($customFields['ReqserRedirect']['jumpSalesChannels']) && $customFields['ReqserRedirect']['jumpSalesChannels'] === true) {
                    $jumpSalesChannels = true;
                } 

                $browserLanguages = explode(',', (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''));
                $region_code_exist = false;
        
                foreach ($browserLanguages as $browserLanguage) {
                    $languageCode = explode(';', $browserLanguage)[0]; // Get the part before ';'
                    if (!$region_code_exist && strpos($languageCode, '-') !== false) {
                        $region_code_exist = true;
                    }
                    $preferred_browser_language = strtolower(trim($languageCode)); // Normalize to lowercase and trim spaces
                    $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $currentDomain, $jumpSalesChannels);
                }
        
                //If there was no redirect yet, we can try again but this time we remove the country code from the preferred browser language
                if (isset($customFields['ReqserRedirect']['redirectOnLanguageOnly']) && $customFields['ReqserRedirect']['redirectOnLanguageOnly'] === true) {
                    foreach ($browserLanguages as $browserLanguage) {
                        $languageCode = explode(';', $browserLanguage)[0]; // Get the part before ';'
                        $preferred_browser_language = strtolower(trim(explode('-', $languageCode)[0])); // Trim and get only the language code
                        $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $currentDomain, $jumpSalesChannels);
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
    
    private function handleLanguageRedirect(string $preferred_browser_language, SalesChannelDomainCollection $salesChannelDomains, $currentDomain, bool $jumpSalesChannels): void
    {
        foreach ($salesChannelDomains as $salesChannelDomain) {
            //If the current domain is the check we continue and only if jumpSalesChannels is true we can look into domains on other sales channels
            if ($currentDomain->getSalesChannelId() == $salesChannelDomain->getSalesChannelId()
                || (!$jumpSalesChannels && $salesChannelDomain->getSalesChannelId() != $currentDomain->getSalesChannelId())
            ) {
                continue;
            }
            $customFields = $salesChannelDomain->getCustomFields();

            //Only allow if redirectInto is set to true
            if (!isset($customFields['ReqserRedirect']['redirectInto']) || $customFields['ReqserRedirect']['redirectInto'] !== true) {
                continue;
            }

            // Check if ReqserRedirect exists and has a languageRedirect array
            if (isset($customFields['ReqserRedirect']['languageRedirect']) && is_array($customFields['ReqserRedirect']['languageRedirect'])) {
                if (in_array($preferred_browser_language, $customFields['ReqserRedirect']['languageRedirect'])) {
                        $response = new RedirectResponse($salesChannelDomain->getUrl());
                        $response->send();
                        exit;
                }
            }
        }
    }

    private function getSalesChannelDomains(SalesChannelContext $context)
    {
        // Retrieve the full collection without limiting to the current sales channel
        $criteria = new Criteria();
        
        // Ensure we're only retrieving domains that have the 'ReqserRedirect' custom field set
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.ReqserRedirect', null)
        ]));
    
        // Get the collection from the repository
        return $this->domainRepository->search($criteria, $context->getContext())->getEntities();
    }


}
