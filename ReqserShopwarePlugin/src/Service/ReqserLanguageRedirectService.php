<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserAppService;
use Reqser\Plugin\Service\ReqserSessionService;
use Reqser\Plugin\Service\ReqserCustomFieldService;
use Reqser\Plugin\Service\ReqserRedirectService;
use Reqser\Plugin\Service\ReqserLanguageSwitchService;
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
    private $languageSwitchService;
    private ?StorefrontRenderEvent $currentEvent = null;
    private ?array $currentCustomFields = null;
    private bool $debugMode = false;
    private bool $debugEchoMode = false;
    private ?array $redirectConfig = null;
    private ?string $primaryBrowserLanguage = null;

    public function __construct(
        EntityRepository $domainRepository,
        ReqserAppService $appService,
        ReqserWebhookService $webhookService,
        ReqserSessionService $sessionService,
        ReqserCustomFieldService $customFieldService,
        ReqserRedirectService $redirectService,
        ReqserLanguageSwitchService $languageSwitchService
    ) {
        $this->domainRepository = $domainRepository;
        $this->appService = $appService;
        $this->webhookService = $webhookService;
        $this->sessionService = $sessionService;
        $this->customFieldService = $customFieldService;
        $this->redirectService = $redirectService;
        $this->languageSwitchService = $languageSwitchService;
    }

    /**
     * Initialize redirect service with event context and current domain configuration
     */
    public function initialize(StorefrontRenderEvent $event, $session, array $customFields, $request, $currentDomain): void
    {
        $this->currentEvent = $event;
        $this->currentCustomFields = $customFields;
        
        // Load all redirect configuration once
        $this->redirectConfig = $this->customFieldService->getRedirectConfiguration($customFields);
        
        // Determine debug modes once and store globally using pre-loaded config
        $this->debugMode = false; //$this->customFieldService->isDebugModeActive($this->redirectConfig, $request, $currentDomain, $this->sessionService);
        $this->debugEchoMode = false; //$this->customFieldService->isDebugEchoModeActive($this->debugMode, $this->redirectConfig);
        
        // Initialize session service with redirect config
        $this->sessionService->initialize($session, $this->redirectConfig);
        
        // Initialize redirect service with debug modes
        $this->redirectService->setDebugModes($this->debugMode, $this->debugEchoMode);
        
        // Calculate primary browser language once
        $this->primaryBrowserLanguage = $this->getPrimaryBrowserLanguage($request);
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
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Headers already sent - redirect not possible', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            return false;
        }

        // Domain configuration validation
        if (!$this->redirectService->isDomainValidForRedirectFrom($this->redirectConfig, $currentDomain)) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Domain validation failed - stopping redirect', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            return false;
        }

        // More checks if the redirect has to be continued
        if (!$this->shouldProcessRedirect($currentDomain)) {
            return false;
        }

        //Check if the current Page we are on is allowed to be redirected
        if (!$this->isCurrentPageFrontPage($request, $currentDomain)) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Not front page - stopping redirect', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            return false;
        }

        // Check for manual language switch events (user override, previous domain choice, etc.)
        if (!$this->languageSwitchService->checkForManualLanguageSwitchEvent($currentDomain, $salesChannelDomains, $this->redirectConfig, $this->debugMode, $this->debugEchoMode, $this->currentEvent)) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Language switch event stopped redirect', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            return false;
        }

        if (!$this->sessionService->validateAndManageSessionRedirects()) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Session validation failed - stopping redirect', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                return false;
        }

        // Check cross sales channel jumping configuration and get appropriate domains
        $jumpSalesChannels = $this->redirectConfig['jumpSalesChannels'] ?? false;
        if ($jumpSalesChannels === true) {
            $salesChannelDomains = $this->getSalesChannelDomains($event->getSalesChannelContext(), $jumpSalesChannels);
        }

        // Check multiple domains availability
        if ($salesChannelDomains->count() <= 1) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Only one domain available - stopping redirect', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            return false;
        }

        // Process browser language redirects
        $this->processBrowserLanguageRedirects($salesChannelDomains, $currentDomain, $jumpSalesChannels, $request);
        return true;
    }


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
    private function shouldProcessRedirect($currentDomain): bool
    {
        // Early exit check - if browser language matches current domain, no redirect needed
        if ($this->userBrowserLanguageMatchesCurrentDomainLanguage($currentDomain)) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User browser language matches current domain language - no redirect needed', 'primaryBrowserLanguage' => $this->primaryBrowserLanguage, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            return false;
        }

        return true;
    }


    /**
     * Check if current page is front page
     */
    private function isCurrentPageFrontPage($request, $currentDomain): bool
    {
        $onlyRedirectFrontPage = $this->redirectConfig['onlyRedirectFrontPage'] ?? false;
        if ($onlyRedirectFrontPage === true) {
        $currentUrl = rtrim($request->getUri(), '/');
        $domainUrl = rtrim($currentDomain->getUrl(), '/');
        
        if ($domainUrl !== $currentUrl) {
            // Check if URL sanitization is enabled
                if ($this->redirectConfig['sanatizeUrlOnFrontPageCheck'] ?? false) {
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
        }
        
        return true;
    }

    /**
     * Process browser language redirects
     */
    private function processBrowserLanguageRedirects($salesChannelDomains, $currentDomain, bool $jumpSalesChannels, $request): void
    {
        if (isset($this->primaryBrowserLanguage)){
            $this->handleLanguageRedirect($this->primaryBrowserLanguage, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $request);
        } else {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Primary browser language not set - should not be possible', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            return;
        }

        if (!($this->redirectConfig['redirectOnDefaultBrowserLanguageOnly'] ?? false)) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Redirect on default browser language only is disabled - getting browser languages', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            $alternativeBrowserLanguages = $this->getAlternativeBrowserLanguages($request);
            foreach ($alternativeBrowserLanguages as $browserLanguage) {
                $this->handleLanguageRedirect($browserLanguage, $salesChannelDomains, $currentDomain, $jumpSalesChannels, $request);
            }
        }
    }



    /**
     * If the User has choosen via Langauge Switch a domain, we will ignore browser language and redirect to this domain
     */
    private function handleUserOverrideLanguageRedirect($currentDomain, SalesChannelDomainCollection $salesChannelDomains): bool
    {
        $redirectBasedOnUserLanguageSwitch = $this->redirectConfig['redirectBasedOnUserLanguageSwitch'] ?? false;

        if ($redirectBasedOnUserLanguageSwitch === true) {
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
                        //Check if the Domain is active and allowed to be redirected into
                        $sessionDomainConfig = $this->customFieldService->getRedirectConfiguration($sessionDomain->getCustomFields());
                         if (!$this->redirectService->isDomainValidForRedirectFromInto($sessionDomainConfig, $sessionDomain)) {
                            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Target domain validation failed - stopping redirect', 'domain_id' => $sessionDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                            return false;
                        }
                         // Delegate to the redirect service
                        $this->redirectService->handleDirectDomainRedirect(
                            $sessionDomain->getUrl(),
                            ($this->redirectConfig['javaScriptRedirect'] ?? false),
                            $this->currentEvent
                        );
                    }
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
    private function userBrowserLanguageMatchesCurrentDomainLanguage($currentDomain): bool
    {
        if ($this->primaryBrowserLanguage === null) {
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
        
        if ($this->primaryBrowserLanguage == $language) {
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
                    
                    // Use redirect service for the actual redirect (handles all cases: headers sent, JavaScript, echo mode, etc.)
                    $this->redirectService->handleDirectDomainRedirect(
                        $salesChannelDomain->getUrl(),
                        ($this->redirectConfig['javaScriptRedirect'] ?? false),
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
