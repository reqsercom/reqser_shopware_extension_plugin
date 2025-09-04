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
    private bool $debugMode;
    private bool $debugEchoMode;
    private ?StorefrontRenderEvent $currentEvent = null;
    private ?string $primaryBrowserLanguage = null;

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
        $this->debugMode = false; // Initialize debug mode as false by default
        $this->debugEchoMode = false; // Initialize debug echo mode as false by default
    }


    /**
     * Get the events this subscriber listens to
     * 
     * @return array Array of events and their corresponding methods
     */
    public static function getSubscribedEvents(): array
    {
        // Define the event(s) this subscriber listens to
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender'
        ];
    }

    /**
     * Handle storefront render events for language-based redirects
     * 
     * @param StorefrontRenderEvent $event The storefront render event
     */
    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        try{           
            // Check if the app is active
            if (!$this->appService->isAppActive()) {
                return;
            }

            $this->currentEvent = $event;
          
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
            
            //Debug Mode Check - update global property if actually active
            $this->debugMode = $this->isDebugModeActive($customFields, $request, $session, $currentDomain);
            if ($this->debugMode) {
                $this->debugEchoMode = isset($customFields['ReqserRedirect']['debugEchoMode']) && $customFields['ReqserRedirect']['debugEchoMode'] === true;
            }
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Debug mode active', 
                    'domain_data' => $currentDomain, 
                    'session_data' => $session->all(),
                    'custom_fields' => $customFields,
                    'current_server_time' => time(),
                    'file' => __FILE__, 
                    'line' => __LINE__
                ], $this->debugEchoMode);
            }

            $javaScriptRedirect = isset($customFields['ReqserRedirect']['javaScriptRedirect']) && $customFields['ReqserRedirect']['javaScriptRedirect'] === true;
            
            //Universal Check if Header was already sent, then we can't redirect anymore
            if (headers_sent() && !$javaScriptRedirect) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Headers already sent - redirect not possible', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return;
            }
           
            //Session Ignore Mode Check
            $sessionIgnoreMode = $this->isSessionIgnoreModeActive($customFields);

            //Domain Configuration Validation
            if (!$this->isDomainValidForRedirect($customFields, $currentDomain)) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Domain validation failed - stopping redirect', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return;
            }

            //Early Exit Check - if browser language matches current domain, no redirect needed
            if ($this->isBrowserLanguageMatchingCurrentDomain($request, $currentDomain)) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Browser language matches current domain - no redirect needed', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return;
            }
      
            $advancedRedirectEnabled = $customFields['ReqserRedirect']['advancedRedirectEnabled'] ?? false;
            
            //User Override Check if the user has pressed the lang
            if ($this->shouldSkipDueToUserOverride($customFields, $session, $advancedRedirectEnabled, $currentDomain)) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Skipping redirect - user override active', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return;
            } else {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User override not found within timeframe, continuing', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            }
            
            //Session Redirect Validation
            if ($sessionIgnoreMode === false) {
                if (!$this->validateAndManageSessionRedirects($customFields, $session, $advancedRedirectEnabled, $currentDomain)) {
                    return;
                }
            }
            //Front Page Only Validation
            if (isset($customFields['ReqserRedirect']['onlyRedirectFrontPage']) && $customFields['ReqserRedirect']['onlyRedirectFrontPage'] === true) {
                if (!$this->isCurrentPageFrontPage($request, $currentDomain)) {
                    if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Not on front page - stopping redirect', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                    return;
                }
            }

            //Cross Sales Channel Jump Configuration
            $jumpSalesChannels = $this->isJumpSalesChannelsEnabled($customFields);
            
            // IF we allow Jump Sales Channels what is not common, we will call again for all domains
            if ($jumpSalesChannels === true){
                $salesChannelDomains = $this->getSalesChannelDomains($event->getSalesChannelContext(), $jumpSalesChannels);
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Jump Sales Channels enabled - calling getSalesChannelDomains again', 'salesChannelDomains' => $salesChannelDomains, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            } 

            

            //Check if we have multiple domains available for redirect
            if ($salesChannelDomains->count() <= 1) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Only one domain available - stopping redirect', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return;
            }

            //Process Browser Language Redirects
            $this->processBrowserLanguageRedirects($customFields, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $javaScriptRedirect);
            
        } catch (\Throwable $e) {
            if (method_exists($this->logger, 'error')) {
                $this->logger->error('Reqser Plugin Error onStorefrontRender', [
                    'message' => $e->getMessage(),
                    'file' => __FILE__, 
                    'line' => __LINE__,
                ]);
            }
            if ($this->debugMode) {
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
    }
    
    /**
     * Handle language redirect for a specific browser language
     * 
     * @param string $preferred_browser_language The preferred browser language code
     * @param SalesChannelDomainCollection $salesChannelDomains Collection of available domains
     * @param object $currentDomain Current domain object
     * @param bool $jumpSalesChannels Whether cross sales channel jumping is enabled
     * @param bool $javaScriptRedirect Whether JavaScript redirect is enabled
     */
    private function handleLanguageRedirect(string $preferred_browser_language, SalesChannelDomainCollection $salesChannelDomains, $currentDomain, bool $jumpSalesChannels, bool $javaScriptRedirect): void
    {
        if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'All Sales Channel Domains to check', 'salesChannelDomains' => $salesChannelDomains, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
        foreach ($salesChannelDomains as $salesChannelDomain) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Checking sales channel domain', 'url' => $salesChannelDomain->url, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            //If the current domain is the check we continue and only if jumpSalesChannels is true we can look into domains on other sales channels
            if ($currentDomain->getId() == $salesChannelDomain->getId()){
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Continue because it is the default domain', 'domain_id' => $salesChannelDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                continue;
            } 
            if (!$jumpSalesChannels && $salesChannelDomain->getSalesChannelId() != $currentDomain->getSalesChannelId()
            ) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Continue', 'url' => $salesChannelDomain->url, 'jumpSalesChannels' => $jumpSalesChannels, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                continue;
            }
            $customFields = $salesChannelDomain->getCustomFields();

            //Only allow if redirectInto is set to true
            if (!isset($customFields['ReqserRedirect']['redirectInto']) || $customFields['ReqserRedirect']['redirectInto'] !== true) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Skipping domain - redirectInto disabled', 'url' => $salesChannelDomain->url, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                continue;
            }

            // Check if ReqserRedirect exists and has a languageRedirect array
            if (isset($customFields['ReqserRedirect']['languageRedirect']) && is_array($customFields['ReqserRedirect']['languageRedirect'])) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Checking language redirect configuration', 'languageRedirect' => $customFields['ReqserRedirect']['languageRedirect'], 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                if (in_array($preferred_browser_language, $customFields['ReqserRedirect']['languageRedirect'])) {                    
                    if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Redirecting now', 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                    if (headers_sent()) {
                        if ($javaScriptRedirect){
                            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Headers already sent - using JavaScript redirect fallback', 'url' => $salesChannelDomain->getUrl(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                            try {
                                // Prevent JavaScript redirect if echo mode is active
                                if ($this->debugMode && $this->debugEchoMode) {
                                    $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'JAVASCRIPT REDIRECT PREVENTED - Echo mode active', 'would_redirect_to' => $salesChannelDomain->getUrl(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                                    exit; // Stop execution without redirecting
                                }
                                
                                $this->injectJavaScriptRedirect($this->currentEvent, $salesChannelDomain->getUrl());
                                exit;
                            } catch (\Throwable $e) {
                                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Error injecting JavaScript redirect', 'message' => $e->getMessage(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                            }
                        }
                    }

                    // Prevent redirect if echo mode is active so we can see the debug output
                    if ($this->debugMode && $this->debugEchoMode) {
                        $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'REDIRECT PREVENTED - Echo mode active', 'would_redirect_to' => $salesChannelDomain->getUrl(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                        exit; // Stop execution without redirecting
                    }
                    
                    $response = new RedirectResponse($salesChannelDomain->getUrl());
                    $response->send();
                    exit;
                }
            }
        }
    }

    /**
     * Check if session ignore mode is active for the current domain
     * 
     * @param array|null $customFields Domain custom fields
     * @return bool Returns true if session ignore mode is active, false otherwise
     */
    private function isSessionIgnoreModeActive(?array $customFields): bool
    {
        return isset($customFields['ReqserRedirect']['sessionIgnoreMode']) && 
               $customFields['ReqserRedirect']['sessionIgnoreMode'] === true;
    }

    /**
     * Check if jump sales channels is enabled
     * 
     * @param array|null $customFields Domain custom fields
     * @return bool Returns true if jump sales channels is enabled, false otherwise
     */
    private function isJumpSalesChannelsEnabled(?array $customFields): bool
    {
        return isset($customFields['ReqserRedirect']['jumpSalesChannels']) && 
               $customFields['ReqserRedirect']['jumpSalesChannels'] === true;
    }

    /**
     * Process browser language redirects with full and language-only matching
     * 
     * @param array|null $customFields Domain custom fields
     * @param object $salesChannelDomains Collection of sales channel domains
     * @param object $currentDomain Current domain object
     * @param bool $jumpSalesChannels Whether cross sales channel jumping is enabled
     * @param bool $javaScriptRedirect Whether JavaScript redirect is enabled

     */
    private function processBrowserLanguageRedirects(?array $customFields, $salesChannelDomains, $currentDomain, bool $jumpSalesChannels, bool $javaScriptRedirect): void
    {
        $browserLanguages = explode(',', (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''));
        
        if ($this->debugMode) {
            $this->webhookService->sendErrorToWebhook([
                'type' => 'debug', 
                'info' => 'Processing browser languages for redirect',
                'browserLanguages' => $browserLanguages, 
                'domain_id' => $currentDomain->getId(), 
                'file' => __FILE__, 
                'line' => __LINE__
            ]);
        }
        
        if (empty($browserLanguages)) {
            return;
        }

        $region_code_exist = false;

        // First pass: Try full language codes (e.g., en-US, de-DE)
        foreach ($browserLanguages as $browserLanguage) {
            $languageCode = explode(';', $browserLanguage)[0];
            if (!$region_code_exist && strpos($languageCode, '-') !== false) {
                $region_code_exist = true;
            }
            $preferred_browser_language = strtolower(trim($languageCode));
            
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Calling language redirect handler', 
                    'preferred_browser_language' => $preferred_browser_language, 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ]);
            }
            
            $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $javaScriptRedirect);
            
            if (isset($customFields['ReqserRedirect']['redirectOnDefaultBrowserLanguageOnly']) && 
                $customFields['ReqserRedirect']['redirectOnDefaultBrowserLanguageOnly'] === true) {
                break;
            }
        }

        // Second pass: Try language-only codes (e.g., en, de) if enabled and region codes existed
        if ($region_code_exist && 
            isset($customFields['ReqserRedirect']['redirectOnLanguageOnly']) && 
            $customFields['ReqserRedirect']['redirectOnLanguageOnly'] === true) {
            
            foreach ($browserLanguages as $browserLanguage) {
                $languageCode = explode(';', $browserLanguage)[0];
                $preferred_browser_language = strtolower(trim(explode('-', $languageCode)[0]));
                
                $this->handleLanguageRedirect($preferred_browser_language, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $javaScriptRedirect);
                
                if (isset($customFields['ReqserRedirect']['redirectOnDefaultBrowserLanguageOnly']) && 
                    $customFields['ReqserRedirect']['redirectOnDefaultBrowserLanguageOnly'] === true) {
                    break;
                }
            }
        }
    }

    /**
     * Check if the current page is the front page of the domain
     * 
     * @param object $request Current request object
     * @param object $currentDomain Current domain object

     * @return bool Returns true if current page is front page, false otherwise
     */
    private function isCurrentPageFrontPage($request, $currentDomain): bool
    {
        // Check if the current page is the sales channel domain front page, not a product or category page
        $currentUrl = rtrim($request->getUri(), '/');
        $domainUrl = rtrim($currentDomain->url, '/');
        
        if ($domainUrl !== $currentUrl) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug',
                    'info' => 'Not on front page - only front page redirects allowed',
                    'currentUrl' => $currentUrl,
                    'domainUrl' => $domainUrl,
                    'domain_id' => $currentDomain->getId(),
                    'file' => __FILE__,
                    'line' => __LINE__
                ]);
            }
            return false; // Not on front page
        }
        
        return true; // Is front page
    }

    /**
     * Validate session redirect conditions and manage session state
     * 
     * @param array|null $customFields Domain custom fields
     * @param object $session Current session object
     * @param bool $advancedRedirectEnabled Whether advanced redirect is enabled

     * @param object $currentDomain Current domain object
     * @return bool Returns true if redirect can proceed, false if should stop
     */
    private function validateAndManageSessionRedirects(?array $customFields, $session, bool $advancedRedirectEnabled, $currentDomain): bool
    {
        if ($session->get('reqser_redirect_done', false)) {
            // Advanced redirect validation for subsequent redirects
            if ($advancedRedirectEnabled === false) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug', 
                        'info' => 'Advanced redirect disabled - stopping', 
                        'domain_id' => $currentDomain->getId(), 
                        'file' => __FILE__, 
                        'line' => __LINE__
                    ]);
                }
                return false;
            }

            $lastRedirectTime = $session->get('reqser_last_redirect_at');
            $gracePeriodMs = $customFields['ReqserRedirect']['gracePeriodMs'] ?? null;
            $blockPeriodMs = $customFields['ReqserRedirect']['blockPeriodMs'] ?? null;
            $maxRedirects = $customFields['ReqserRedirect']['maxRedirects'] ?? null;

            if ($maxRedirects === null && $gracePeriodMs === null && $blockPeriodMs === null) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug', 
                        'info' => 'No redirect settings configured', 
                        'domain_id' => $currentDomain->getId(), 
                        'file' => __FILE__, 
                        'line' => __LINE__
                    ]);
                }
                return false;
            }

            $redirectCount = $session->get('reqser_redirect_count', 0);
            $redirectCount++;
            $session->set('reqser_redirect_count', $redirectCount);
            
            if ($maxRedirects !== null && $redirectCount >= $maxRedirects) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug', 
                        'info' => 'Max redirects reached - stopping', 
                        'redirectCount' => $redirectCount, 
                        'maxRedirects' => $maxRedirects, 
                        'domain_id' => $currentDomain->getId(), 
                        'file' => __FILE__, 
                        'line' => __LINE__
                    ]);
                }
                return false;
            }

            // Check if the last redirect was done after the grace period but within the block period
            $currentTimestamp = microtime(true) * 1000;
            if ($gracePeriodMs !== null && $lastRedirectTime !== null && 
                $lastRedirectTime < $currentTimestamp - $gracePeriodMs && 
                $lastRedirectTime > $currentTimestamp - $blockPeriodMs) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug', 
                        'info' => 'Redirect blocked by timing restrictions', 
                        'gracePeriodMs' => $gracePeriodMs,
                        'blockPeriodMs' => $blockPeriodMs,
                        'domain_id' => $currentDomain->getId(), 
                        'file' => __FILE__, 
                        'line' => __LINE__
                    ]);
                }
                return false;
            }

            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Redirect timing validation passed', 
                    'currentTimestamp' => $currentTimestamp, 
                    'lastRedirectTime' => $lastRedirectTime, 
                    'gracePeriodMs' => $gracePeriodMs, 
                    'blockPeriodMs' => $blockPeriodMs, 
                    'maxRedirects' => $maxRedirects, 
                    'redirectCount' => $redirectCount, 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ]);
            }
            $session->set('reqser_last_redirect_at', microtime(true) * 1000);
        } else {
            // First redirect - initialize session
            $session->set('reqser_redirect_done', true);
            $session->set('reqser_last_redirect_at', microtime(true) * 1000);
            $session->set('reqser_redirect_count', 0);
        }

        return true; // Redirect can proceed
    }

    /**
     * Check if redirect should be skipped due to user override settings
     * 
     * @param array|null $customFields Domain custom fields
     * @param object $session Current session object
     * @param bool $advancedRedirectEnabled Whether advanced redirect is enabled

     * @param object $currentDomain Current domain object
     * @return bool Returns true if redirect should be skipped, false otherwise
     */
    private function shouldSkipDueToUserOverride(?array $customFields, $session, bool $advancedRedirectEnabled, $currentDomain): bool
    {
        $userOverrideEnabled = $customFields['ReqserRedirect']['userOverrideEnabled'] ?? false;
        
        // Check if user override conditions are met
        if ($userOverrideEnabled === true && $advancedRedirectEnabled === true && $session->get('reqser_redirect_user_override_timestamp', false)) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User override active - checking conditions', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            $overrideTimestamp = $session->get('reqser_redirect_user_override_timestamp');
            
            if (isset($customFields['ReqserRedirect']['overrideIgnorePeriodS'])) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'overrideIgnorePeriodS isset', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                // Check if the override timestamp is younger than the overrideIgnorePeriodS
                if ($overrideTimestamp > time() - $customFields['ReqserRedirect']['overrideIgnorePeriodS']) {
                    if ($this->debugMode) {
                        $this->webhookService->sendErrorToWebhook([
                            'type' => 'debug', 
                            'info' => 'User override active - within ignore period', 
                            'overrideTimestamp' => $overrideTimestamp,
                            'overrideIgnorePeriodS' => $customFields['ReqserRedirect']['overrideIgnorePeriodS'],
                            'domain_id' => $currentDomain->getId(), 
                            'file' => __FILE__, 
                            'line' => __LINE__
                        ]);
                    }
                    return true; // Skip redirect
                } else {
                    if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Override Timestamp '.$overrideTimestamp.' is not bigger than time(): '.time().' minus overrideIgnorePeriodS: '.$customFields['ReqserRedirect']['overrideIgnorePeriodS'], 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                }
            } else {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug', 
                        'info' => 'User override active - no period restriction', 
                        'overrideTimestamp' => $overrideTimestamp,
                        'domain_id' => $currentDomain->getId(), 
                        'file' => __FILE__, 
                        'line' => __LINE__
                    ]);
                }
                return true; // Skip redirect (no period restriction)
            }
        }
        
        return false; // Don't skip redirect
    }

    /**
     * Validate if domain is properly configured for redirect operations
     * 
     * @param array|null $customFields Domain custom fields
     * @param object $currentDomain Current domain object

     * @return bool Returns true if domain is valid for redirect, false otherwise
     */
    private function isDomainValidForRedirect(?array $customFields, $currentDomain): bool
    {
        // Check if domain is active
        if (!isset($customFields['ReqserRedirect']['active']) || $customFields['ReqserRedirect']['active'] !== true) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Domain is not active - stopping redirect', 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ]);
            }
            return false;
        }

        // Check if redirectFrom is enabled
        if (!isset($customFields['ReqserRedirect']['redirectFrom']) || $customFields['ReqserRedirect']['redirectFrom'] !== true) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Domain redirectFrom disabled - stopping redirect', 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ]);
            }
            return false;
        }

        return true;
    }

    /**
     * Check if debug mode is active for the current domain and request
     * 
     * @param array|null $customFields Domain custom fields
     * @param object $request Current request object
     * @param object $session Current session object
     * @param object $currentDomain Current domain object
     * @return bool Returns true if debug mode is active, false otherwise
     */
    private function isDebugModeActive(?array $customFields, $request, $session, $currentDomain): bool
    {
        // Check if debug mode is enabled in domain configuration
        if (!isset($customFields['ReqserRedirect']['debugMode']) || $customFields['ReqserRedirect']['debugMode'] !== true) {
            return false;
        }

        // Check if debugModeIp is set and validate the request IP
        if (isset($customFields['ReqserRedirect']['debugModeIp'])) {
            $clientIp = $request->getClientIp();
            $debugModeIp = $customFields['ReqserRedirect']['debugModeIp'];
            
            if ($clientIp == $debugModeIp) {
                            // IP matches, activate debug mode
            $this->webhookService->sendErrorToWebhook([
                'type' => 'debug', 
                'info' => 'Debug mode activated - IP match', 
                'clientIp' => $clientIp, 
                'debugModeIp' => $debugModeIp, 
                'sessionValues' => $session->all(), 
                'domain_id' => $currentDomain->getId(), 
                'file' => __FILE__, 
                'line' => __LINE__
            ]);
                return true;
            } else {
                // IP doesn't match, debug mode is not active
                return false;
            }
        } else {
            // No IP restriction, activate debug mode
            $this->webhookService->sendErrorToWebhook([
                'type' => 'debug', 
                'info' => 'Debug mode activated - no IP restriction', 
                'sessionValues' => $session->all(), 
                'domain_id' => $currentDomain->getId(), 
                'file' => __FILE__, 
                'line' => __LINE__
            ]);
            return true;
        }
    }

    /**
     * Check if the user's main browser language matches the current domain's language
     * This allows for early exit optimization when no redirect is needed
     * 
     * @param object $request The current request object
     * @param object $currentDomain The current domain object
     * @return bool Returns true if there is no redirect needed or possible
     */
    private function isBrowserLanguageMatchingCurrentDomain($request, $currentDomain): bool
    {
        // Get primary browser language (simple and cached)
        $primaryBrowserLanguage = $this->getPrimaryBrowserLanguage($request);
        
        if (empty($primaryBrowserLanguage)) {
            return true; //if empty no redirect possible
        }

        //check on Custom Fields for definition of the language code
        $customFields = $currentDomain->getCustomFields();
        if (isset($customFields['ReqserRedirect']['languageCode'])) {
            if ($primaryBrowserLanguage == $customFields['ReqserRedirect']['languageCode']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve sales channel domains with ReqserRedirect custom fields
     * 
     * @param SalesChannelContext $context The sales channel context
     * @return mixed Collection of sales channel domains
     */
    private function getSalesChannelDomains(SalesChannelContext $context, ?bool $jumpSalesChannels = null)
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

    /**
     * Inject JavaScript redirect when headers are already sent
     * 
     * @param StorefrontRenderEvent $event The storefront render event
     * @param string $redirectUrl The URL to redirect to
     */
    private function injectJavaScriptRedirect(StorefrontRenderEvent $event, string $redirectUrl): void
    {
        $escapedUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
        
        // Since we can't use setParameters, we'll output JavaScript directly
        echo "
        <script type='text/javascript'>
            // Reqser Language Redirect - JavaScript fallback
            (function() {
                var redirectUrl = '{$escapedUrl}';
                console.log('Reqser: Headers already sent, using JavaScript redirect to:', redirectUrl);
                
                // Small delay to ensure page elements are loaded
                setTimeout(function() {
                    window.location.href = redirectUrl;
                }, 100);
                
                // Backup: If setTimeout doesn't work, try immediate redirect
                if (document.readyState === 'complete') {
                    window.location.href = redirectUrl;
                }
            })();
        </script>";
    }

    /**
     * Get the primary browser language (simple and cached)
     * 
     * @param object $request The current request object
     * @return string|null The primary browser language code (e.g., 'en', 'de') or null if not available
     */
    private function getPrimaryBrowserLanguage($request): ?string
    {
        if ($this->primaryBrowserLanguage !== null) {
            return $this->primaryBrowserLanguage;
        }

        // Get the Accept-Language header
        $acceptLanguage = $request->headers->get('Accept-Language');
        
        if (empty($acceptLanguage)) {
            $this->primaryBrowserLanguage = null;
            return null;
        }

        // Get the first (most preferred) language
        $primaryLanguage = explode(',', $acceptLanguage)[0];
        // Remove quality values (q=0.8) and get language code
        $languageCode = explode(';', $primaryLanguage)[0];
        // Extract just the language part (remove country code: en-US -> en)
        $this->primaryBrowserLanguage = strtolower(trim(explode('-', $languageCode)[0]));
        
        return $this->primaryBrowserLanguage;
    }
}
