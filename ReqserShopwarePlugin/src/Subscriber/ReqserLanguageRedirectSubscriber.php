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
    private ?int $redirectCount = null;

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
            $this->debugEchoMode = $this->debugMode && $this->customFieldBool($customFields, 'debugEchoMode');
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Debug mode active', 'domain_data' => $currentDomain, 'session_data' => $session->all(), 'custom_fields' => $customFields, 'current_server_time' => time(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            }

            $javaScriptRedirect = $this->customFieldBool($customFields, 'javaScriptRedirect');
            
            //Universal Check if Header was already sent, then we can't redirect anymore
            if (headers_sent() && !$javaScriptRedirect) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Headers already sent - redirect not possible', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return;
            }

            //Early Exit Check - if browser language matches current domain, no redirect needed
            if ($this->isBrowserLanguageMatchingCurrentDomain($request, $currentDomain)) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Browser language matches current domain - no redirect needed', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return;
            }
           
            //Session Ignore Mode Check
            $sessionIgnoreMode = $this->customFieldBool($customFields, 'sessionIgnoreMode');

            //Domain Configuration Validation
            if (!$this->isDomainValidForRedirect($customFields, $currentDomain)) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Domain validation failed - stopping redirect', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return;
            }
      
            $advancedRedirectEnabled = $this->customFieldBool($customFields, 'advancedRedirectEnabled');
            
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
            if ($this->customFieldBool($customFields, 'onlyRedirectFrontPage')) {
                if (!$this->isCurrentPageFrontPage($request, $currentDomain)) {
                    if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Not on front page - stopping redirect', 'domain_id' => $domainId, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                    return;
                }
            }

            //Cross Sales Channel Jump Configuration
            $jumpSalesChannels = $this->customFieldBool($customFields, 'jumpSalesChannels');
            
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
            $this->processBrowserLanguageRedirects($customFields, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $javaScriptRedirect, $request, $session);
            
        } catch (\Throwable $e) {
            if (method_exists($this->logger, 'error')) {
                $this->logger->error('Reqser Plugin Error onStorefrontRender', ['message' => $e->getMessage(), 'file' => __FILE__, 'line' => __LINE__]);
            }
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook(['type' => 'error', 'function' => 'onStorefrontRender', 'message' => $e->getMessage() ?? 'unknown', 'trace' => $e->getTraceAsString() ?? 'unknown', 'timestamp' => date('Y-m-d H:i:s'), 'file' => __FILE__, 'line' => __LINE__], false);
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
     * @param object $session Current session object
     */
    private function handleLanguageRedirect(string $preferred_browser_language, SalesChannelDomainCollection $salesChannelDomains, $currentDomain, bool $jumpSalesChannels, bool $javaScriptRedirect, $session): void
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
        if (!$this->customFieldBool($customFields, 'redirectInto')) {
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
                                
                                // Prepare session variables before redirect
                                $this->prepareRedirectSession($session);
                                
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
                    
                    // Prepare session variables before redirect
                    $this->prepareRedirectSession($session);
                    
                    $response = new RedirectResponse($salesChannelDomain->getUrl());
                    $response->send();
                    exit;
                }
            }
        }
    }


    /**
     * Process browser language redirects with full and language-only matching
     * 
     * @param array|null $customFields Domain custom fields
     * @param object $salesChannelDomains Collection of sales channel domains
     * @param object $currentDomain Current domain object
     * @param bool $jumpSalesChannels Whether cross sales channel jumping is enabled
     * @param bool $javaScriptRedirect Whether JavaScript redirect is enabled
     * @param object $request Current request object
     * @param object $session Current session object
     */
    private function processBrowserLanguageRedirects(?array $customFields, $salesChannelDomains, $currentDomain, bool $jumpSalesChannels, bool $javaScriptRedirect, $request, $session): void
    {
        if (isset($this->primaryBrowserLanguage)){
            $this->handleLanguageRedirect($this->primaryBrowserLanguage, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $javaScriptRedirect, $session);
        } else {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Primary browser language not set - should not be possible', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            //try again to get primary browser language
            $primaryBrowserLanguage = $this->getPrimaryBrowserLanguage($request);
            if (isset($primaryBrowserLanguage)){
                $this->handleLanguageRedirect($primaryBrowserLanguage, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $javaScriptRedirect, $session);
            } else {
                return;
            }
        }

        if (!$this->customFieldBool($customFields, 'redirectOnDefaultBrowserLanguageOnly')) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Redirect on default browser language only is disabled - getting browser languages', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            $alternativeBrowserLanguages = $this->getAlternativeBrowserLanguages($request);
            foreach ($alternativeBrowserLanguages as $browserLanguage) {
                $this->handleLanguageRedirect($browserLanguage, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $javaScriptRedirect, $session);
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
                $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Not on front page - only front page redirects allowed', 'currentUrl' => $currentUrl, 'domainUrl' => $domainUrl, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
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
        if ($session->get('reqser_redirect_done', false) && $advancedRedirectEnabled === true) {
            $lastRedirectTime = $session->get('reqser_last_redirect_at', null);
            $gracePeriodMs = $customFields['ReqserRedirect']['gracePeriodMs'] ?? null;
            $blockPeriodMs = $customFields['ReqserRedirect']['blockPeriodMs'] ?? null;
            $maxRedirects = $customFields['ReqserRedirect']['maxRedirects'] ?? null;

            if ($maxRedirects === null && $gracePeriodMs === null && $blockPeriodMs === null) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'No redirect settings configured', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                }
                return true;
            }

            $redirectCount = $this->getRedirectCount($session);
            
            if ($maxRedirects !== null && $redirectCount >= $maxRedirects) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Max redirects reached - stopping', 'redirectCount' => $redirectCount, 'maxRedirects' => $maxRedirects, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                }
                return false;
            }

            // Check if the last redirect was done after the grace period but within the block period
            $currentTimestamp = microtime(true) * 1000;
            if ($gracePeriodMs !== null && $lastRedirectTime !== null && 
                $lastRedirectTime < $currentTimestamp - $gracePeriodMs && 
                $lastRedirectTime > $currentTimestamp - $blockPeriodMs) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Redirect blocked by timing restrictions', 'gracePeriodMs' => $gracePeriodMs, 'blockPeriodMs' => $blockPeriodMs, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                }
                return false;
            }

            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Redirect timing validation passed', 'currentTimestamp' => $currentTimestamp, 'lastRedirectTime' => $lastRedirectTime, 'gracePeriodMs' => $gracePeriodMs, 'blockPeriodMs' => $blockPeriodMs, 'maxRedirects' => $maxRedirects, 'redirectCount' => $redirectCount, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            }
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
        $userOverrideEnabled = $this->customFieldBool($customFields, 'userOverrideEnabled');
        
        // Check if user override conditions are met
        if ($userOverrideEnabled === true && $advancedRedirectEnabled === true && $session->get('reqser_redirect_user_override_timestamp', false)) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User override active - checking conditions', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            $overrideTimestamp = $session->get('reqser_redirect_user_override_timestamp');
            
            if (isset($customFields['ReqserRedirect']['overrideIgnorePeriodS'])) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'overrideIgnorePeriodS isset', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                // Check if the override timestamp is younger than the overrideIgnorePeriodS
                if ($overrideTimestamp > time() - $customFields['ReqserRedirect']['overrideIgnorePeriodS']) {
                    if ($this->debugMode) {
                        $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User override active - within ignore period', 'overrideTimestamp' => $overrideTimestamp, 'overrideIgnorePeriodS' => $customFields['ReqserRedirect']['overrideIgnorePeriodS'], 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                    }
                    return true; // Skip redirect
                } else {
                    if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Override Timestamp '.$overrideTimestamp.' is not bigger than time(): '.time().' minus overrideIgnorePeriodS: '.$customFields['ReqserRedirect']['overrideIgnorePeriodS'], 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                }
            } else {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User override active - no period restriction', 'overrideTimestamp' => $overrideTimestamp, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                }
                return true; // Skip redirect (no period restriction)
            }
        } else {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User override not active - skipping redirect', 'userOverrideEnabled' => $userOverrideEnabled, 'advancedRedirectEnabled' => $advancedRedirectEnabled, 'overrideTimestamp' => $session->get('reqser_redirect_user_override_timestamp', false),  'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
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
        if (!$this->customFieldBool($customFields, 'active')) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Domain is not active - stopping redirect', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            }
            return false;
        }

        // Check if redirectFrom is enabled
        if (!$this->customFieldBool($customFields, 'redirectFrom')) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Domain redirectFrom disabled - stopping redirect', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
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
        if (!$this->customFieldBool($customFields, 'debugMode')) {
            return false;
        }

        // Check if debugModeIp is set and validate the request IP
        if (isset($customFields['ReqserRedirect']['debugModeIp'])) {
            $clientIp = $request->getClientIp();
            $debugModeIp = $customFields['ReqserRedirect']['debugModeIp'];
            
            if ($clientIp == $debugModeIp) {
                            // IP matches, activate debug mode
            $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Debug mode activated - IP match', 'clientIp' => $clientIp, 'debugModeIp' => $debugModeIp, 'sessionValues' => $session->all(), 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return true;
            } else {
                // IP doesn't match, debug mode is not active
                return false;
            }
        } else {
            // No IP restriction, activate debug mode
            $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Debug mode activated - no IP restriction', 'sessionValues' => $session->all(), 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
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

    /**
     * Get alternative browser languages (all except the primary one)
     * 
     * @param object $request Current request object
     * @return array Array of alternative browser languages
     */
    private function getAlternativeBrowserLanguages($request): array
    {
        $acceptLanguage = $request->headers->get('Accept-Language', '');
        
        if (empty($acceptLanguage)) {
            return [];
        }

        $languages = explode(',', $acceptLanguage);
        $alternativeLanguages = [];
        
        // Skip the first (primary) language and process the rest
        for ($i = 1; $i < count($languages); $i++) {
            $languageCode = explode(';', $languages[$i])[0];
            // Extract just the language part (remove country code: en-US -> en)
            $language = strtolower(trim(explode('-', $languageCode)[0]));
            
            if (!empty($language) && !in_array($language, $alternativeLanguages)) {
                $alternativeLanguages[] = $language;
            }
        }
        
        return $alternativeLanguages;
    }

    /**
     * Check if a ReqserRedirect custom field is set and true
     * 
     * @param array|null $customFields The custom fields array
     * @param string $fieldName The field name (e.g., 'debugMode', 'javaScriptRedirect')
     * @return bool Returns true if field exists and is true, false otherwise
     */
    private function customFieldBool(?array $customFields, string $fieldName): bool
    {
        return ($customFields['ReqserRedirect'][$fieldName] ?? false) === true;
    }

    /**
     * Get the redirect count from session and cache it globally
     * 
     * @param object $session The session object
     * @return int The current redirect count
     */
    private function getRedirectCount($session): int
    {
        if ($this->redirectCount === null) {
            $this->redirectCount = $session->get('reqser_redirect_count', 0);
        }
        return $this->redirectCount;
    }

    /**
     * Prepare session variables before redirecting
     * 
     * @param object $session The session object
     * @return void
     */
    private function prepareRedirectSession($session): void
    {
        // Set redirect done flag
        $session->set('reqser_redirect_done', true);
        
        // Set last redirect timestamp in milliseconds
        $currentTimestamp = microtime(true) * 1000;
        $session->set('reqser_last_redirect_at', $currentTimestamp);
        
        // Increment and update redirect count
        $this->incrementRedirectCount($session);
    }

    /**
     * Increment the redirect count in session
     * 
     * @param object $session The session object
     * @return int The new redirect count
     */
    private function incrementRedirectCount($session): int
    {
        $redirectCount = $this->getRedirectCount($session);
        $redirectCount++;
        $this->redirectCount = $redirectCount;
        $session->set('reqser_redirect_count', $redirectCount);
        return $redirectCount;
    }
}
