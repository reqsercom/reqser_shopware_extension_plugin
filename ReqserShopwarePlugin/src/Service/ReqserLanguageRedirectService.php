<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Reqser\Plugin\Service\ReqserSessionService;
use Reqser\Plugin\Service\ReqserCustomFieldService;
use Reqser\Plugin\Service\ReqserRedirectService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\Framework\Context;

class ReqserLanguageRedirectService
{
    private $domainRepository;
    private $sessionService;
    private $customFieldService;
    private $redirectService;
    private $cache;
    private ?array $redirectConfig = null;
    private ?string $primaryBrowserLanguage = null;
    private $salesChannelDomains = null;
    private $currentDomain = null;
    private $request = null;
    private ?array $originalPageUrl = null;

    public function __construct(
        EntityRepository $domainRepository,
        ReqserSessionService $sessionService,
        ReqserCustomFieldService $customFieldService,
        ReqserRedirectService $redirectService,
        $cache
    ) {
        $this->domainRepository = $domainRepository;
        $this->sessionService = $sessionService;
        $this->customFieldService = $customFieldService;
        $this->redirectService = $redirectService;
        $this->cache = $cache;
    }

    /**
     * Initialize
     */
    public function initialize($session, array $redirectConfig, $request, $currentDomain, $salesChannelDomains): void
    {
        $this->currentDomain = $currentDomain;
        $this->redirectConfig = $redirectConfig;
        $this->request = $request;
        $this->sessionService->initialize($session);
        $this->salesChannelDomains = $salesChannelDomains;
        $this->primaryBrowserLanguage = $this->getPrimaryBrowserLanguage();
    }

    /**
     * Check if redirect should be processed (adapted for initialized parameters)
     */
    public function shouldProcessRedirect(): bool
    {
        if (!$this->isDomainValidForRedirectFrom()) {
            return false;
        } elseif ($this->userBrowserLanguageMatchesCurrentDomainLanguage()) {
            return false;
        } elseif (!$this->currentPageIsAllowed()) {
            return false;
        } 
        return true;
    }

    
    /**
     * Check if redirect should be processed based on manual language switch
     */
    public function shouldProcessRedirectBasedOnManualLanguageSwitch(): bool
    {
        if ($this->redirectConfig['skipRedirectAfterManualLanguageSwitch'] ?? false) {
            if ($this->shouldSkipDueToManualLanguageSwitchEvent()) {
                return false; 
            }
        }
        return true;
    }

    /**
     * Check if redirect should be processed based on session data
     */
    public function shouldProcessRedirectBasedOnSessionData(): bool
    {
        if (!$this->redirectConfig['sessionIgnoreMode'] ?? false) {
            if (!$this->redirectAllowedBySessionData()) {
                return false; 
            }
        }
        return true;
    }

    /**
     * Check if redirect should be skipped due to session data
     */
    public function redirectAllowedBySessionData(): bool
    {
        //Session Data
        $redirectCount = $this->sessionService->getRedirectCount();
        $scriptCallCount = $this->sessionService->getScriptCallCount();
        $lastRedirectTime = $this->sessionService->getLastRedirectTime();

        //Config Data
        $gracePeriodMs = $this->redirectConfig['gracePeriodMs'] ?? null;
        $blockPeriodMs = $this->redirectConfig['blockPeriodMs'] ?? null;
        $maxRedirects = $this->redirectConfig['maxRedirects'] ?? null;
        $maxScriptCalls = $this->redirectConfig['maxScriptCalls'] ?? null;

        //check for valid config data
        if ($maxRedirects === null && $gracePeriodMs === null && $blockPeriodMs === null && $maxScriptCalls === null) {
            //If there are no settings we cannot allow the redirect
            return false;
        }

        //Max Redirect Check
        if ($maxRedirects !== null && $redirectCount >= $maxRedirects) {
            return false;
        }

        //Max Script Call Check
        if ($maxScriptCalls !== null && $scriptCallCount >= $maxScriptCalls) {
            return false;
        }

        //Increment the script call count now
        $this->sessionService->incrementScriptCallCount($scriptCallCount);

        // Check if the last redirect is to close to now so we will also block the redirect to prevent any chance of looping
        $currentTimestamp = microtime(true) * 1000;
        if ($gracePeriodMs !== null && $lastRedirectTime !== null && 
            $lastRedirectTime < $currentTimestamp - $gracePeriodMs && 
            $lastRedirectTime > $currentTimestamp - $blockPeriodMs) {
            return false;
        }

        return true;
    }

    /**
     * Check if redirect should be skipped due to user override settings
     */
    public function shouldSkipDueToManualLanguageSwitchEvent(): bool
    {
        if ($this->sessionService->getUserlanguageSwitchTimestamp()) {
            $languageSwitchTimestamp = $this->sessionService->getUserlanguageSwitchTimestamp();
            $userLanguageSwitchIgnorePeriodS = $this->redirectConfig['userLanguageSwitchIgnorePeriodS'] ?? null;
            
            if ($userLanguageSwitchIgnorePeriodS !== null) {
                // Check if the override timestamp is younger than the userLanguageSwitchIgnorePeriodS
                if ($languageSwitchTimestamp > time() - $userLanguageSwitchIgnorePeriodS) {
                    return true;
                }
            } else {
                return true;
            }
        }
        
        return false;
    }


    /**
     * Check if domain is valid for redirect operations
     * 
     * @return bool Returns true if domain is valid for redirect, false otherwise
     */
    public function isDomainValidForRedirectFrom(): bool
    {
        // Check if domain is active
        if (!($this->redirectConfig['active'] ?? false)) {
            return false;
        } elseif (!($this->redirectConfig['redirectFrom'] ?? false)) {
            return false;
        }

        return true;
    }


    /**
     * Check if current page is front page (simplified and optimized for AJAX context)
     */
    private function currentPageIsAllowed(): bool
    {
        $onlyRedirectFrontPage = $this->redirectConfig['onlyRedirectFrontPage'] ?? false;
        if (!$onlyRedirectFrontPage) {
            // If onlyRedirectFrontPage is false, allow all pages
            return true;
        }

        // Get the parsed original page URL using reusable method
        $parsedReferer = $this->getOriginalPageUrl();
        
        if (empty($parsedReferer)) {
            // No referer means we can't determine the original page
            return false;
        }

        // Get current domain URL (clean base URL)
        $currentDomainUrl = rtrim($this->currentDomain->getUrl(), '/');
        
        // Build clean referer URL (scheme + host + port + path, no query/fragment)
        $cleanRefererUrl = $parsedReferer['scheme'] . '://' . $parsedReferer['host'];
        if (isset($parsedReferer['port'])) {
            $cleanRefererUrl .= ':' . $parsedReferer['port'];
        }
        
        // Add path if it exists and is not just '/'
        $path = $parsedReferer['path'] ?? '/';
        if ($path !== '/') {
            $cleanRefererUrl .= rtrim($path, '/');
        }
        
        // Check if the clean referer URL matches the domain base URL (front page)
        return $cleanRefererUrl === $currentDomainUrl || $cleanRefererUrl === $currentDomainUrl . '/';
    }

    /**
     * Get and cache the parsed original page URL from referer header
     */
    public function getOriginalPageUrl(): ?array
    {
        // Return cached result if already parsed
        if ($this->originalPageUrl !== null) {
            return $this->originalPageUrl;
        }

        // Get the original page URL from the referer header
        $refererUrl = $this->request->headers->get('Referer') ?? $_SERVER['HTTP_REFERER'] ?? null;
        
        if (!$refererUrl) {
            $this->originalPageUrl = [];
            return $this->originalPageUrl;
        }

        // Parse referer URL
        $parsedReferer = parse_url($refererUrl);
        if (!$parsedReferer || !isset($parsedReferer['scheme']) || !isset($parsedReferer['host'])) {
            $this->originalPageUrl = [];
            return $this->originalPageUrl;
        }

        // Cache and return the parsed URL
        $this->originalPageUrl = $parsedReferer;
        return $this->originalPageUrl;
    }

    /**
     * Get query parameters from the original page URL
     */
    private function getOriginalPageQueryParams(): array
    {
        $originalUrl = $this->getOriginalPageUrl();
        
        if (empty($originalUrl) || !isset($originalUrl['query'])) {
            return [];
        }

        // Parse query string into array
        $queryParams = [];
        parse_str($originalUrl['query'], $queryParams);
        
        return $queryParams;
    }

    /**
     * Retrieve the new domain to redirect to based on browser language
     */
    public function retrieveNewDomainToRedirectTo(): ?string
    {
        // Get the primary browser language
        if (!$this->primaryBrowserLanguage) {
            return null;
        }

        //Check if the user has choosen one manualy so we will use this domain as his last choice
        if ($this->redirectConfig['redirectToUserPreviouslyChosenDomain']) {
            $sessionLanguageId = $this->sessionService->getUserOverrideLanguageId();
            if ($sessionLanguageId) {
                //retrieve the first domain in our collection that matches this language id
                $matchingDomain = $this->salesChannelDomains->filter(
                    function($domain) use ($sessionLanguageId) {
                        // Check if language ID matches
                        if ($domain->getLanguageId() !== $sessionLanguageId) {
                            return false;
                        }
                        
                        // Check if this domain is valid for redirect into
                        $domainCustomFields = $domain->getCustomFields();
                        $domainConfig = $this->customFieldService->getRedirectIntoConfiguration($domainCustomFields);
                        
                        return $this->redirectService->isDomainValidForRedirectInto($domainConfig, $domain);
                    }
                )->first();
                
                
                if ($matchingDomain) {
                    return $this->buildRedirectUrlWithParams($matchingDomain->getUrl());
                }
            }
        }

        // Find matching domain using database filtering for maximum performance
        $matchingDomain = $this->findMatchingDomainByLanguage();
        if ($matchingDomain) {
            return $this->buildRedirectUrlWithParams($matchingDomain->getUrl());
        }

        return null;
    }

    /**
     * Build redirect URL with preserved GET parameters from original request
     * Always preserves reqser_debug_mode, other parameters based on configuration
     */
    private function buildRedirectUrlWithParams(string $baseUrl): string
    {
        // If no request available, return base URL
        if (!$this->request) {
            return $baseUrl;
        }

        // Get query parameters from the original page URL (referer), not the AJAX request
        $queryParams = $this->getOriginalPageQueryParams();
        
        // If no parameters, return base URL
        if (empty($queryParams)) {
            return $baseUrl;
        }

        // Check configuration for preserving URL parameters
        $preserveUrlParameters = $this->redirectConfig['preserveUrlParameters'] ?? false;
        
        // Always preserve reqser* parameters when debug mode is active
        $parametersToKeep = [];
        $debugMode = isset($queryParams['reqserdebugmode']) && $queryParams['reqserdebugmode'] === 'true';
        
        if ($debugMode) {
            // When debug mode is active, preserve all reqser* parameters for performance debugging
            foreach ($queryParams as $key => $value) {
                if (stripos($key, 'reqser') === 0) {
                    $parametersToKeep[$key] = $value;
                }
            }
        }
        
        // If configuration allows, preserve all other parameters
        if ($preserveUrlParameters) {
            $parametersToKeep = $queryParams;
        }
        
        // If no parameters to keep, return base URL
        if (empty($parametersToKeep)) {
            return $baseUrl;
        }

        // Build query string from parameters
        $queryString = http_build_query($parametersToKeep);
        
        // Append query string to base URL
        return $baseUrl . '?' . $queryString;
    }

    /**
     * Find a matching domain by language code using foreach for optimal performance (early exit)
     * 
     * @return SalesChannelDomainEntity|null The first matching domain or null
     */
    private function findMatchingDomainByLanguage(): ?SalesChannelDomainEntity
    {
        // Use foreach for maximum performance - early exit on first match
        foreach ($this->salesChannelDomains as $domain) {
            // Skip the current domain
            if ($domain->getId() === $this->currentDomain->getId()) {
                continue;
            }

            // Check if this domain is valid for redirect into
            $domainCustomFields = $domain->getCustomFields();
            $domainConfig = $this->customFieldService->getRedirectIntoConfiguration($domainCustomFields);

            // Check if the domain's language matches the browser language
            $domainLanguageCode = $domainConfig['languageCode'] ?? null;
            if ($domainLanguageCode && $domainLanguageCode === $this->primaryBrowserLanguage) {
                // Additional validation: check if domain is valid for redirect
                if ($this->redirectService->isDomainValidForRedirectInto($domainConfig, $domain)) {
                    return $domain; // Early exit - return first match immediately
                }
            }
        }
        
        return null;
    }

    /**
     * Get primary browser language from request
     */
    public function getPrimaryBrowserLanguage(): ?string
    {
        if ($this->primaryBrowserLanguage) {
            return $this->primaryBrowserLanguage;
        }

        if (!$this->request) {
            return null;
        }
        
        $acceptLanguage = $this->request->headers->get('Accept-Language', '');
        
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
    private function userBrowserLanguageMatchesCurrentDomainLanguage(): bool
    {
        if ($this->primaryBrowserLanguage === null) {
            return true;
        }

        $languageCode = $this->redirectConfig['languageCode'];
        if ($languageCode === null) {
            return true;
        }

        // Extract language code from locale (e.g., en-GB -> en)
        $language = strtolower(explode('-', $languageCode)[0]);
        
        if ($this->primaryBrowserLanguage == $language) {
            return true;
        }

        return false;
    }


    /**
     * Retrieve sales channel domains with ReqserRedirect custom fields by sales channel ID (cached method)
     */
    public function getSalesChannelDomainsById(string $salesChannelId): SalesChannelDomainCollection
    {
        // Create cache key based on sales channel ID
        $cacheKey = 'reqser_domains_' . $salesChannelId;
        
        // If cache is available, use it
        if ($this->cache) {
            try {
                return $this->cache->get($cacheKey, function ($item) use ($salesChannelId) {
                    // Cache for 1 hour (3600 seconds) - domain configs rarely change
                    $item->expiresAfter(3600);
                    
                    // Query database only when cache expires
                    return $this->queryDomainsByChannelId($salesChannelId);
                });
                
            } catch (\Throwable $e) {
                // If cache fails, fall back to direct database query
                return $this->queryDomainsByChannelId($salesChannelId);
            }
        }
        
        // No cache available, query directly
        return $this->queryDomainsByChannelId($salesChannelId);
    }


    /**
     * Query domains from database by sales channel ID
     */
    private function queryDomainsByChannelId(string $salesChannelId): SalesChannelDomainCollection
    {
        $criteria = new Criteria();
        
        // Ensure we're only retrieving domains that have the 'ReqserRedirect' custom field set
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.ReqserRedirect', null)
        ]));
        
        // Filter to current sales channel only (no cross-channel jumping)
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
    
        // Get the collection from the repository using default context
        return $this->domainRepository->search($criteria, Context::createDefaultContext())->getEntities();
    }

    /**
     * Update session data
     */
    public function updateSessionData(): void
    {
        $this->sessionService->prepareRedirectSession();
    }
}
