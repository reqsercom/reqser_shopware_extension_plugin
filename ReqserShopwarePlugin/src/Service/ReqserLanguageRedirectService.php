<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserAppService;
use Reqser\Plugin\Service\ReqserSessionService;
use Reqser\Plugin\Service\ReqserCustomFieldService;
use Reqser\Plugin\Service\ReqserRedirectService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Storefront\Event\StorefrontRenderEvent;

class ReqserLanguageRedirectService
{
    private $domainRepository;
    private $appService;
    private $webhookService;
    private $sessionService;
    private $customFieldService;
    private $redirectService;
    private ?StorefrontRenderEvent $currentEvent = null;
    private ?array $currentCustomFields = null;
    private bool $debugMode = false;
    private bool $debugEchoMode = false;
    private ?array $redirectConfig = null;

    public function __construct(
        EntityRepository $domainRepository,
        ReqserAppService $appService,
        ReqserWebhookService $webhookService,
        ReqserSessionService $sessionService,
        ReqserCustomFieldService $customFieldService,
        ReqserRedirectService $redirectService
    ) {
        $this->domainRepository = $domainRepository;
        $this->appService = $appService;
        $this->webhookService = $webhookService;
        $this->sessionService = $sessionService;
        $this->customFieldService = $customFieldService;
        $this->redirectService = $redirectService;
    }

    /**
     * Initialize redirect service with event context and current domain configuration
     */
    public function initialize(StorefrontRenderEvent $event, $session, array $customFields, $request, $currentDomain): void
    {
        $this->currentEvent = $event;
        $this->sessionService->initialize($session);
        $this->currentCustomFields = $customFields;
        
        // Load all redirect configuration once
        $this->redirectConfig = $this->customFieldService->getRedirectConfiguration($customFields);
        
        // Determine debug modes once and store globally using pre-loaded config
        $this->debugMode = $this->customFieldService->isDebugModeActive($this->redirectConfig, $request, $currentDomain, $this->sessionService);
        $this->debugEchoMode = $this->debugMode && ($this->redirectConfig['debug_echo_mode'] ?? false);
        
        // Initialize redirect service with debug modes
        $this->redirectService->setDebugModes($this->debugMode, $this->debugEchoMode);
    }

    /**
     * Check if the app is active
     */
    public function isAppActive(): bool
    {
        return $this->appService->isAppActive();
    }


    /**
     * Process redirect logic for the given context
     */
    public function processRedirect(
        StorefrontRenderEvent $event,
        $currentDomain,
        SalesChannelDomainCollection $salesChannelDomains
    ): bool {
        $request = $event->getRequest();
        
        // Check if headers are already sent
        if (headers_sent() && !($this->redirectConfig['javascript_redirect'] ?? false)) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Headers already sent - redirect not possible', 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ], $this->debugEchoMode);
            }
            return false;
        }

        // Early exit checks
        if (!$this->shouldProcessRedirect($currentDomain, $request)) {
            return false;
        }

        // Advanced redirect validation
        $advancedRedirectEnabled = $this->redirectConfig['advanced_redirect_enabled'] ?? false;
        
        // User override check
        $userOverrideEnabled = $this->redirectConfig['user_override_enabled'] ?? false;
        $overrideIgnorePeriodS = $this->redirectConfig['override_ignore_period_s'] ?? null;
        
        if ($this->sessionService->shouldSkipDueToUserOverride($userOverrideEnabled, $advancedRedirectEnabled, $overrideIgnorePeriodS)) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Skipping redirect - user override active', 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ], $this->debugEchoMode);
            }
            return false;
        }

        // User domain override check
        if ($this->redirectConfig['redirect_if_user_override_domain_id_exists'] ?? false) {
            $overrideDomainId = $this->sessionService->shouldRedirectToUserOverrideDomain($currentDomain->getId(), $salesChannelDomains);
            if ($overrideDomainId) {
                $sessionDomain = $salesChannelDomains->get($overrideDomainId);
                if ($sessionDomain) {
                    if ($this->debugMode) {
                        $this->webhookService->sendErrorToWebhook([
                            'type' => 'debug', 
                            'info' => 'User override language redirect handled', 
                            'domain_id' => $currentDomain->getId(), 
                            'file' => __FILE__, 
                            'line' => __LINE__
                        ], $this->debugEchoMode);
                    }
                    $this->handleDirectDomainRedirect($sessionDomain);
                    return true;
                }
            }
        }

        // Session redirect validation
        // Note: sessionIgnoreMode logic will be handled in SessionService
        if ($advancedRedirectEnabled) {
            $gracePeriodMs = $this->redirectConfig['grace_period_ms'] ?? null;
            $blockPeriodMs = $this->redirectConfig['block_period_ms'] ?? null;
            $maxRedirects = $this->redirectConfig['max_redirects'] ?? null;
            $maxScriptCalls = $this->redirectConfig['max_script_calls'] ?? null;
            
            if (!$this->sessionService->validateAndManageSessionRedirects($advancedRedirectEnabled, $gracePeriodMs, $blockPeriodMs, $maxRedirects, $maxScriptCalls)) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug', 
                        'info' => 'Session validation failed - stopping redirect', 
                        'domain_id' => $currentDomain->getId(), 
                        'file' => __FILE__, 
                        'line' => __LINE__
                    ], $this->debugEchoMode);
                }
                return false;
            }
        }

        // Front page validation
        if ($this->redirectConfig['only_redirect_front_page'] ?? false) {
            if (!$this->isCurrentPageFrontPage($request, $currentDomain)) {
                if ($this->debugMode) {
                    $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug', 
                        'info' => 'Not front page - stopping redirect', 
                        'domain_id' => $currentDomain->getId(), 
                        'file' => __FILE__, 
                        'line' => __LINE__
                    ], $this->debugEchoMode);
                }
                return false;
            }
        }

        // Check cross sales channel jumping configuration and get appropriate domains
        $jumpSalesChannels = $this->redirectConfig['jump_sales_channels'] ?? false;
        if ($jumpSalesChannels === true) {
            $salesChannelDomains = $this->getSalesChannelDomains($event->getSalesChannelContext());
        }

        // Check multiple domains availability
        if ($salesChannelDomains->count() <= 1) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Only one domain available - stopping redirect', 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ], $this->debugEchoMode);
            }
            return false;
        }

        // Process browser language redirects
        return $this->processBrowserLanguageRedirects($salesChannelDomains, $currentDomain, $jumpSalesChannels, $request);
    }

    /**
     * Handle direct redirect to a specific domain (bypassing language checks)
     */
    public function handleDirectDomainRedirect($targetDomain): void
    {
        if ($this->debugMode) {
            $this->webhookService->sendErrorToWebhook([
                'type' => 'debug', 
                'info' => 'Direct domain redirect - user previously chose this domain', 
                'target_url' => $targetDomain->getUrl(), 
                'domain_id' => $targetDomain->getId(),
                'file' => __FILE__, 
                'line' => __LINE__
            ], $this->debugEchoMode);
        }

        // Delegate to the redirect service
        $this->redirectService->handleDirectDomainRedirect(
            $targetDomain->getUrl(),
            ($this->redirectConfig['javascript_redirect'] ?? false),
            $this->currentEvent
        );
    }

    // [Continue with other private methods...]


    /**
     * Retrieve sales channel domains with ReqserRedirect custom fields
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
     * Check if redirect should be processed
     */
    private function shouldProcessRedirect($currentDomain, $request): bool
    {
        // Early exit check - if browser language matches current domain, no redirect needed
        $primaryBrowserLanguage = $this->getPrimaryBrowserLanguage($request);
        
        if ($this->userBrowserLanguageMatchesCurrentDomainLanguage($primaryBrowserLanguage, $currentDomain)) {
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'User browser language matches current domain language - no redirect needed', 
                    'primaryBrowserLanguage' => $primaryBrowserLanguage, 
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ], $this->debugEchoMode);
            }
            return false;
        }

        return true;
    }


    /**
     * Check if current page is front page
     */
    private function isCurrentPageFrontPage($request, $currentDomain): bool
    {
        $currentUrl = rtrim($request->getUri(), '/');
        $domainUrl = rtrim($currentDomain->getUrl(), '/');
        
        if ($domainUrl !== $currentUrl) {
            // Check if URL sanitization is enabled
            if ($this->redirectConfig['sanitize_url_on_front_page_check'] ?? false) {
                $sanitizedCurrentUrl = $this->redirectService->sanitizeUrl($currentUrl);
                if ($domainUrl === $sanitizedCurrentUrl) {
                    return true;
                }
            }
            
            if ($this->debugMode) {
                $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Not on front page - only front page redirects allowed', 'currentUrl' => $currentUrl, 'domainUrl' => $domainUrl, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            }
            return false;
        }
        
        return true;
    }

    /**
     * Process browser language redirects
     */
    private function processBrowserLanguageRedirects($salesChannelDomains, $currentDomain, bool $jumpSalesChannels, $request): bool
    {
        $primaryBrowserLanguage = $this->getPrimaryBrowserLanguage($request);
        
        if (isset($primaryBrowserLanguage)){
            $this->handleLanguageRedirect($primaryBrowserLanguage, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $request);
        } else {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Primary browser language not set - should not be possible', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            //try again to get primary browser language
            $primaryBrowserLanguage = $this->getPrimaryBrowserLanguage($request);
            if (isset($primaryBrowserLanguage)){
                $this->handleLanguageRedirect($primaryBrowserLanguage, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $request);
            } else {
                return false;
            }
        }

        if (!($this->redirectConfig['redirect_on_default_browser_language_only'] ?? false)) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Redirect on default browser language only is disabled - getting browser languages', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            $alternativeBrowserLanguages = $this->getAlternativeBrowserLanguages($request);
            foreach ($alternativeBrowserLanguages as $browserLanguage) {
                $this->handleLanguageRedirect($browserLanguage, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $request);
            }
        }
        
        return true;
    }



    /**
     * Handle user override language redirect based on stored domain ID
     */
    private function handleUserOverrideLanguageRedirect($currentDomain, SalesChannelDomainCollection $salesChannelDomains): bool
    {
        // Get the stored domain ID from session
        $sessionDomainId = $this->sessionService->getUserOverrideDomainId();
        
        // Check if session domain ID exists and matches current domain ID
        if ($sessionDomainId) {
            if ($sessionDomainId === $currentDomain->getId()) {
                return true;
            } else {
                // Check if the domain is in the sales channel domains
                $sessionDomain = $salesChannelDomains->get($sessionDomainId);
                if ($sessionDomain) {
                    // Redirect to this domain as the user has chosen it once already
                    $this->handleDirectDomainRedirect($sessionDomain);
                }
            }
        }   
        
        return false;
    }

    // Session and redirect management methods - delegated to SessionService
    private function prepareRedirectSession(): void
    {   
        $this->sessionService->prepareRedirectSession();
    }


    /**
     * Get primary browser language from request
     */
    private function getPrimaryBrowserLanguage($request): ?string
    {
        $acceptLanguage = $request->headers->get('Accept-Language', '');
        
        if (empty($acceptLanguage)) {
            return null;
        }

        $languages = explode(',', $acceptLanguage);
        $primaryLanguage = explode(';', $languages[0])[0];
        
        // Extract just the language part (remove country code: en-US -> en)
        return strtolower(trim(explode('-', $primaryLanguage)[0]));
    }

    /**
     * Check if user's browser language matches current domain language
     */
    private function userBrowserLanguageMatchesCurrentDomainLanguage(?string $primaryBrowserLanguage, $currentDomain): bool
    {
        if ($primaryBrowserLanguage === null) {
            return false;
        }

        $domainLanguage = $currentDomain->getLanguage();
        if ($domainLanguage === null) {
            return false;
        }

        $languageCode = $domainLanguage->getLocale()->getCode();
        if ($languageCode === null) {
            return false;
        }

        // Extract language code from locale (e.g., en-GB -> en)
        $language = strtolower(explode('-', $languageCode)[0]);
        
        if ($primaryBrowserLanguage == $language) {
            return true;
        }

        return false;
    }


    /**
     * Handle language redirect for a specific browser language
     * 
     * @param string $preferred_browser_language The preferred browser language code
     * @param SalesChannelDomainCollection $salesChannelDomains Collection of available domains
     * @param object $currentDomain Current domain object
     * @param bool $jumpSalesChannels Whether cross sales channel jumping is enabled
     * @param object $request Current request object
     */
    private function handleLanguageRedirect(string $preferred_browser_language, SalesChannelDomainCollection $salesChannelDomains, $currentDomain, bool $jumpSalesChannels, $request): void
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
            if (!$this->customFieldService->getBool($customFields, 'redirectInto')) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Skipping domain - redirectInto disabled', 'url' => $salesChannelDomain->url, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                continue;
            }

            // Check if ReqserRedirect exists and has a languageRedirect array
            if (isset($customFields['ReqserRedirect']['languageRedirect']) && is_array($customFields['ReqserRedirect']['languageRedirect'])) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Checking language redirect configuration', 'languageRedirect' => $customFields['ReqserRedirect']['languageRedirect'], 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                if (in_array($preferred_browser_language, $customFields['ReqserRedirect']['languageRedirect'])) {                    
                    if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Redirecting now', 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                    if (headers_sent()) {
                        if (($this->redirectConfig['javascript_redirect'] ?? false)){
                            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Headers already sent - using JavaScript redirect fallback', 'url' => $salesChannelDomain->getUrl(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                            try {
                                // Prevent JavaScript redirect if echo mode is active
                                if ($this->debugMode && $this->debugEchoMode) {
                                    $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'JAVASCRIPT REDIRECT PREVENTED - Echo mode active', 'would_redirect_to' => $salesChannelDomain->getUrl(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                                    exit; // Stop execution without redirecting
                                } else {
                                    // Prepare session variables before redirect
                                    $this->sessionService->prepareRedirectSession();
                                }
                                
                                $this->redirectService->injectJavaScriptRedirect($this->currentEvent, $salesChannelDomain->getUrl());
                                exit;
                            } catch (\Throwable $e) {
                                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Error injecting JavaScript redirect', 'message' => $e->getMessage(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                            }
                        }
                    }

                    // Use redirect service for the actual redirect
                    $this->redirectService->handleDirectDomainRedirect(
                        $salesChannelDomain->getUrl(),
                        ($this->redirectConfig['javascript_redirect'] ?? false),
                        $this->currentEvent
                    );
                }
            }
        }
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
}
