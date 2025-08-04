<?php
declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Reqser\Plugin\Service\ReqserNotificationService;
use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserAppService;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Psr\Log\LoggerInterface;


class ReqserLanguageRedirectSubscriber implements EventSubscriberInterface
{
    private $requestStack;
    private $notificationService;
    private $salesChannelContextFactory;
    private $domainRepository;
    private $appService;
    private $webhookService;
    private LoggerInterface $logger;

    public function __construct(
        RequestStack $requestStack, 
        ReqserNotificationService $notificationService, 
        CachedSalesChannelContextFactory $salesChannelContextFactory, 
        EntityRepository $domainRepository,
        ReqserAppService $appService,
        ReqserWebhookService $webhookService,
        LoggerInterface $logger
        )
    {
        $this->requestStack = $requestStack;
        $this->notificationService = $notificationService;
        $this->salesChannelContextFactory = $salesChannelContextFactory;
        $this->domainRepository = $domainRepository;
        $this->appService = $appService;
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
            // Check if the app is active
            if (!$this->appService->isAppActive()) {
                return;
            }

            $request = $event->getRequest();
            $domainId = $request->attributes->get('sw-domain-id');
            

            // Retrieve sales channel domains for the current context
            $salesChannelDomains = $this->getSalesChannelDomains($event->getSalesChannelContext());
            $currentDomain = $salesChannelDomains->get($domainId);
            if (!$currentDomain) {
                return;
            }

            $session = $request->getSession(); // Get the session from the request
            $customFields = $currentDomain->getCustomFields();
            $debugMode = false;
            if (isset($customFields['ReqserRedirect']['debugMode']) && $customFields['ReqserRedirect']['debugMode'] === true) {
                $debugMode = true;
                $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Debug Mode activ', 'sessionValues' => $session->all(), 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
            }
            $sessionIgnoreMode = false;
            if (isset($customFields['ReqserRedirect']['sessionIgnoreMode']) && $customFields['ReqserRedirect']['sessionIgnoreMode'] === true) {
                $sessionIgnoreMode = true;
            }
            if (!isset($customFields['ReqserRedirect']['active']) || $customFields['ReqserRedirect']['active'] !== true) {
                if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'domain is not active, return', 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                return;
            } elseif (!isset($customFields['ReqserRedirect']['redirectFrom']) || $customFields['ReqserRedirect']['redirectFrom'] !== true) {
                if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'redirectFrom is not true, return', 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                return;
            }

            $advancedRedirectEnabled = $customFields['ReqserRedirect']['advancedRedirectEnabled'] ?? false;
            $userOverrideEnabled = $customFields['ReqserRedirect']['userOverrideEnabled'] ?? false;

            if ($userOverrideEnabled === true && $advancedRedirectEnabled === true && $session->get('reqser_redirect_domain_user_override', false)) {
                $overrideDomainId = $session->get('reqser_redirect_domain_user_override');
                if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'No redirect possible because of domain user override', 'overrideDomainId' => $overrideDomainId, 'file' => __FILE__, 'line' => __LINE__]);
                return;
            }

            if ($sessionIgnoreMode === false && !headers_sent()) {
                if ($session->get('reqser_redirect_done', false)) {

                    if ($advancedRedirectEnabled === false) {
                        if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Advanced redirect is not enabled, return', 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                        return;
                    }

                    $lastRedirectTime = $session->get('reqser_last_redirect_at');
                    $gracePeriodMs = $customFields['ReqserRedirect']['gracePeriodMs'] ?? null;
                    $blockPeriodMs = $customFields['ReqserRedirect']['blockPeriodMs'] ?? null;
                    $maxRedirects = $customFields['ReqserRedirect']['maxRedirects'] ?? null;

                    if ($maxRedirects === null && $gracePeriodMs === null && $blockPeriodMs === null) {
                        if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'No redirect possible because of no settings', 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                        return;
                    }

                    $redirectCount = $session->get('reqser_redirect_count', 0);
                    $redirectCount++;
                    $session->set('reqser_redirect_count', $redirectCount);
                    if ($maxRedirects !== null && $redirectCount >= $maxRedirects) {
                        if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Max redirects reached, no redirect possible', 'redirectCount' => $redirectCount, 'maxRedirects' => $maxRedirects, 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                        return;
                    }

                    // Check if the last redirect was done after the grace period but within the block period, in that case, we should return as no redirect is allowed
                    $currentTimestamp = microtime(true) * 1000;
                    if ($gracePeriodMs !== null && $lastRedirectTime !== null && $lastRedirectTime < $currentTimestamp - $gracePeriodMs && $lastRedirectTime > $currentTimestamp - $blockPeriodMs) {
                        if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Last redirect was more than ' . $gracePeriodMs . ' ms ago but less than ' . $blockPeriodMs . ' ms ago, no redirect possible', 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                        return;
                    }

                    if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Last redirect was either within the grace period or outside the block period, redirect possible', 'currentTimestamp' => $currentTimestamp, 'lastRedirectTime' => $lastRedirectTime, 'gracePeriodMs' => $gracePeriodMs, 'blockPeriodMs' => $blockPeriodMs, 'maxRedirects' => $maxRedirects, 'redirectCount' => $redirectCount, 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                    $session->set('reqser_last_redirect_at', microtime(true) * 1000);
                    
                } else {
                    $session->set('reqser_redirect_done', true);
                    $session->set('reqser_last_redirect_at', microtime(true) * 1000);
                    $session->set('reqser_redirect_count', 0);
                }
            } elseif (headers_sent()) {
                if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Headers already sent, no redirect possible any more', 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                return;
            }

            if (isset($customFields['ReqserRedirect']['onlyRedirectFrontPage']) && $customFields['ReqserRedirect']['onlyRedirectFrontPage'] === true) {
                //Now lets check if the current page is the sales channel domain, and not already something more like a product or category page
                $currentUrl = rtrim($request->getUri(), '/');
                $domainUrl = rtrim($currentDomain->url, '/');
                if ($domainUrl !== $currentUrl) {
                    if ($debugMode) $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug',
                        'info' => 'currentUrl is not the same as the domain url',
                        'currentUrl' => $currentUrl,
                        'domainUrl' => $domainUrl,
                        'domain_id' => $currentDomain,
                        'file' => __FILE__,
                        'line' => __LINE__
                    ]);
                    return;
                }                
            }

            //Has to be bigger than one since the first entry should be the current one for a redirect
            if ($salesChannelDomains->count() > 1) {
                //If the current sales channel is allowing to jump Sales Channels on Redirect we need also retrieve the other domains
                $jumpSalesChannels = false;
                if (isset($customFields['ReqserRedirect']['jumpSalesChannels']) && $customFields['ReqserRedirect']['jumpSalesChannels'] === true) {
                    $jumpSalesChannels = true;
                } 

                $browserLanguages = explode(',', (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''));
                if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'browserLanguages' => $browserLanguages, 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                if (empty($browserLanguages)) return;
                $region_code_exist = false;
        
                foreach ($browserLanguages as $browserLanguage) {
                    $languageCode = explode(';', $browserLanguage)[0]; // Get the part before ';'
                    if (!$region_code_exist && strpos($languageCode, '-') !== false) {
                        $region_code_exist = true;
                    }
                    $preferred_browser_language = strtolower(trim($languageCode)); // Normalize to lowercase and trim spaces
                    if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'calling handleLanguageRedirect()', 'preferred_browser_language' => $preferred_browser_language, 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                    $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $debugMode);
                    if (isset($customFields['ReqserRedirect']['redirectOnDefaultBrowserLanguageOnly']) && $customFields['ReqserRedirect']['redirectOnDefaultBrowserLanguageOnly'] === true) {
                        break;
                    }
                }
        
                //If there was no redirect yet, we can try again but this time we remove the country code from the preferred browser language
                if ($region_code_exist && isset($customFields['ReqserRedirect']['redirectOnLanguageOnly']) && $customFields['ReqserRedirect']['redirectOnLanguageOnly'] === true) {
                    foreach ($browserLanguages as $browserLanguage) {
                        $languageCode = explode(';', $browserLanguage)[0]; // Get the part before ';'
                        $preferred_browser_language = strtolower(trim(explode('-', $languageCode)[0])); // Trim and get only the language code
                        $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $debugMode);
                        if (isset($customFields['ReqserRedirect']['redirectOnDefaultBrowserLanguageOnly']) && $customFields['ReqserRedirect']['redirectOnDefaultBrowserLanguageOnly'] === true) {
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            if (method_exists($this->logger, 'error')) {
                $this->logger->error('Reqser Plugin Error onStorefrontRender', [
                    'message' => $e->getMessage(),
                    'file' => __FILE__, 
                    'line' => __LINE__,
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
    
    private function handleLanguageRedirect(string $preferred_browser_language, SalesChannelDomainCollection $salesChannelDomains, $currentDomain, bool $jumpSalesChannels, bool $debugMode): void
    {
        if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'All Sales Channel Domains to check', 'salesChannelDomains' => $salesChannelDomains, 'file' => __FILE__, 'line' => __LINE__]);
        foreach ($salesChannelDomains as $salesChannelDomain) {
            if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Working on each Sales Channel', 'url' => $salesChannelDomain->url, 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
            //If the current domain is the check we continue and only if jumpSalesChannels is true we can look into domains on other sales channels
            if ($currentDomain->getId() == $salesChannelDomain->getId()){
                if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Continue because it is the default domain', 'domain_id' => $salesChannelDomain->getId(), 'file' => __FILE__, 'line' => __LINE__]);
                continue;
            } 
            if (!$jumpSalesChannels && $salesChannelDomain->getSalesChannelId() != $currentDomain->getSalesChannelId()
            ) {
                if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Continue', 'url' => $salesChannelDomain->url, 'jumpSalesChannels' => $jumpSalesChannels, 'file' => __FILE__, 'line' => __LINE__]);
                continue;
            }
            $customFields = $salesChannelDomain->getCustomFields();

            //Only allow if redirectInto is set to true
            if (!isset($customFields['ReqserRedirect']['redirectInto']) || $customFields['ReqserRedirect']['redirectInto'] !== true) {
                if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Continue redirectInto false', 'url' => $salesChannelDomain->url, 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                continue;
            }

            // Check if ReqserRedirect exists and has a languageRedirect array
            if (isset($customFields['ReqserRedirect']['languageRedirect']) && is_array($customFields['ReqserRedirect']['languageRedirect'])) {
                if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Checking Language Redirect', 'languageRedirect' => $customFields['ReqserRedirect']['languageRedirect'], 'domain_id' => $currentDomain, 'file' => __FILE__, 'line' => __LINE__]);
                if (in_array($preferred_browser_language, $customFields['ReqserRedirect']['languageRedirect'])) {
                    if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Redirecting now', 'file' => __FILE__, 'line' => __LINE__]);
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
