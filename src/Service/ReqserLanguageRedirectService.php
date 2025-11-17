<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Reqser\Plugin\Service\ReqserSessionService;
use Reqser\Plugin\Service\ReqserCustomFieldService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\Framework\Context;

class ReqserLanguageRedirectService
{
    // Cache expiration time in seconds (1 hour)
    private const CACHE_EXPIRATION_TIME = 3600;
    
    private $domainRepository;
    private $sessionService;
    private $customFieldService;
    private $cache;
    private string $environment;
    private ?array $redirectConfig = null;
    private ?string $primaryBrowserLanguage = null;
    private ?array $alternativeBrowserLanguages = null;
    private $salesChannelDomains = null;
    private $currentDomain = null;
    private $request = null;
    private ?array $originalPageUrl = null;
    private ?array $domainMappings = null;

    public function __construct(
        EntityRepository $domainRepository,
        ReqserSessionService $sessionService,
        ReqserCustomFieldService $customFieldService,
        $cache,
        string $environment
    ) {
        $this->domainRepository = $domainRepository;
        $this->sessionService = $sessionService;
        $this->customFieldService = $customFieldService;
        $this->cache = $cache;
        $this->environment = $environment;
    }

    /**
     * Initialize and return redirect config from domain mappings
     */
    public function initialize($session, $request, $currentDomain, $salesChannelDomains, string $salesChannelId): ?array
    {
        $this->currentDomain = $currentDomain;
        $this->request = $request;
        $this->sessionService->initialize($session);
        $this->salesChannelDomains = $salesChannelDomains;
        
        // Initialize cached domain mappings for global access
        $this->domainMappings = $this->getCachedDomainMappings($salesChannelId, $salesChannelDomains);
        
        // Extract redirect config from domain mappings (already processed and cached)
        $currentDomainId = $currentDomain->getId();
        if (isset($this->domainMappings['domainInformation'][$currentDomainId]['redirectConfig'])) {
            $this->redirectConfig = $this->domainMappings['domainInformation'][$currentDomainId]['redirectConfig'];
        }
        
        $this->primaryBrowserLanguage = $this->getPrimaryBrowserLanguage();
        
        // Return the redirect config for use in controller
        return $this->redirectConfig;
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
            $sessionLanguageId = $this->sessionService->getSessionUserManualLanguageSwitchId();
            if ($sessionLanguageId) {
                // Direct O(1) lookup using cached language ID to domain ID mapping
                if (isset($this->domainMappings['languageIdToDomainId'][$sessionLanguageId])) {
                    $domainId = $this->domainMappings['languageIdToDomainId'][$sessionLanguageId];
                    //if this domain is is the current one we must return null
                    if ($domainId === $this->currentDomain->getId()) {
                        return null;
                    } elseif (in_array($domainId, $this->domainMappings['redirectIntoDomains']) && 
                              isset($this->domainMappings['domainInformation'][$domainId]['url'])) {
                        $domainUrl = $this->domainMappings['domainInformation'][$domainId]['url'];
                        return $this->buildRedirectUrlWithParams($domainUrl);
                    }
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
     * Find a matching domain by language code using cached domain mappings for ultra-fast lookup
     * 
     * @return SalesChannelDomainEntity|null The first matching domain or null
     */
    private function findMatchingDomainByLanguage(): ?SalesChannelDomainEntity
    {
        // Direct lookup using cached domain mappings - O(1) complexity!
        if (isset($this->domainMappings['domainLanguageCode'][$this->primaryBrowserLanguage])) {
            $domainId = $this->domainMappings['domainLanguageCode'][$this->primaryBrowserLanguage];
            
            // Skip if it's the current domain
            if ($domainId !== $this->currentDomain->getId()) {
                // Check if domain is allowed for redirect into
                if (in_array($domainId, $this->domainMappings['redirectIntoDomains'])) {
                    $domain = $this->salesChannelDomains->get($domainId);
                    if ($domain) {
                        return $domain;
                    }
                }
            }
        }

        // Handle alternative language redirect if configured
        if (($this->redirectConfig['redirectToAlternativeLanguage'] ?? false) &&
            isset($this->primaryBrowserLanguage) && //Added from 2.0.5 to prevent bots to be redirected to the alternative language if they have no language set
            !in_array($this->primaryBrowserLanguage, $this->getAlternativeBrowserLanguages()) &&
            isset($this->domainMappings['domainLanguageCode'][$this->redirectConfig['alternativeRedirectLanguageCode']])) {
            
            $alternativeDomainId = $this->domainMappings['domainLanguageCode'][$this->redirectConfig['alternativeRedirectLanguageCode']];
            
            // Skip if it's the current domain
            if ($alternativeDomainId !== $this->currentDomain->getId()) {
                // Check if domain is allowed for redirect into
                if (in_array($alternativeDomainId, $this->domainMappings['redirectIntoDomains'])) {
                    $alternativeDomain = $this->salesChannelDomains->get($alternativeDomainId);
                    if ($alternativeDomain) {
                        return $alternativeDomain;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get primary browser language from request
     * Ultra-fast extraction of just the first language code
     */
    public function getPrimaryBrowserLanguage(): ?string
    {
        if ($this->primaryBrowserLanguage) {
            return $this->primaryBrowserLanguage;
        }

        if (!$this->request) {
            return null;
        }

        //If we will check for Redirect Alternative Language we can create the primary browser language
        if ($this->redirectConfig['redirectToAlternativeLanguage'] ?? false) {
            $this->getAlternativeBrowserLanguages();
            if (isset($this->primaryBrowserLanguage)) return $this->primaryBrowserLanguage;
        }
        
        $acceptLanguage = $this->request->headers->get('Accept-Language', '');
        
        if (empty($acceptLanguage)) {
            return null;
        }

        // Ultra-fast extraction of just the first language code
        // Matches first occurrence: en-US -> en, fr-FR -> fr, de -> de
        if (preg_match('/([a-z]{2})(?:-[a-z]{2})?/i', $acceptLanguage, $match)) {
            $this->primaryBrowserLanguage = strtolower($match[1]);
        }
        
        return $this->primaryBrowserLanguage;
    }

    /**
     * Get alternative browser languages from request (all languages except the primary one)
     * Returns array of language codes ordered by browser preference
     * Cached globally to avoid multiple parsing of the same Accept-Language header
     */
    public function getAlternativeBrowserLanguages(): array
    {
        // Return cached result if already parsed
        if ($this->alternativeBrowserLanguages !== null) {
            return $this->alternativeBrowserLanguages;
        }

        if (!$this->request) {
            return [];
        }

        $acceptLanguage = $this->request->headers->get('Accept-Language', '');

        $this->alternativeBrowserLanguages = [];

        if (empty($acceptLanguage)) {
            return $this->alternativeBrowserLanguages;
        }

        // Extract all language codes in one pass
        if (!preg_match_all('/([a-z]{2})(?:-[a-z]{2})?/i', $acceptLanguage, $matches)) {
            return $this->alternativeBrowserLanguages;
        }

        // Ultra-fast processing: combine operations to avoid intermediate arrays
        $seen = [];
        $alternatives = [];
        $primary = null;
        
        // Process matches in one loop - faster than multiple array operations
        foreach ($matches[1] as $lang) {
            $langCode = strtolower($lang);
            
            // Skip duplicates
            if (isset($seen[$langCode])) {
                continue;
            }
            $seen[$langCode] = true;
            
            // First unique language is primary
            if ($primary === null) {
                $primary = $langCode;
                // Set primary if not already cached
                if ($this->primaryBrowserLanguage === null) {
                    $this->primaryBrowserLanguage = $primary;
                }
            } else {
                // All others are alternatives
                $alternatives[] = $langCode;
            }
        }
        
        $this->alternativeBrowserLanguages = $alternatives;
        
        return $this->alternativeBrowserLanguages;
    }

    /**
     * Get user manual language switch ID for debug purposes only
     * Transfer function to avoid loading session service in controller for debug data
     */
    public function getUserManualLanguageSwitchIdForDebug(): ?string
    {
        return $this->sessionService->getSessionUserManualLanguageSwitchId();
    }

    /**
     * Get cache debug information for troubleshooting
     */
    public function getCacheDebugInfo(string $salesChannelId): array
    {
        $cacheInfo = [
            'cache_available' => $this->cache !== null,
            'environment' => $this->environment,
            'caching_enabled' => !$this->isNonProductionEnvironment(),
            'cache_keys' => []
        ];

        if ($this->cache) {
            $domainCacheKey = 'reqser_domains_' . $salesChannelId;
            $processedCacheKey = 'reqser_processed_domains_' . $salesChannelId;

            try {
                // Get actual cached data for debugging
                $cacheInfo['cache_keys'] = [
                    $domainCacheKey => [
                        'description' => 'Domain collection cache',
                        'exists' => $this->cache->hasItem($domainCacheKey),
                        'data' => null
                    ],
                    $processedCacheKey => [
                        'description' => 'Processed domain mappings cache', 
                        'exists' => $this->cache->hasItem($processedCacheKey),
                        'data' => null
                    ]
                ];

                // Get actual cached data if keys exist
                if ($cacheInfo['cache_keys'][$domainCacheKey]['exists']) {
                    try {
                        $cachedDomains = $this->cache->getItem($domainCacheKey)->get();
                        if ($cachedDomains) {
                            // Use JSON encode to serialize all domain data for debugging
                            $cacheInfo['cache_keys'][$domainCacheKey]['data'] = [
                                'type' => 'SalesChannelDomainCollection',
                                'count' => $cachedDomains->count(),
                                'serialized_data' => json_encode($cachedDomains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                            ];
                        } else {
                            $cacheInfo['cache_keys'][$domainCacheKey]['data'] = 'Cache exists but contains null data';
                        }
                    } catch (\Throwable $e) {
                        $cacheInfo['cache_keys'][$domainCacheKey]['data'] = 'Error reading cache: ' . $e->getMessage();
                    }
                }

                if ($cacheInfo['cache_keys'][$processedCacheKey]['exists']) {
                    try {
                        $cachedMappings = $this->cache->getItem($processedCacheKey)->get();
                        $cacheInfo['cache_keys'][$processedCacheKey]['data'] = $cachedMappings;
                    } catch (\Throwable $e) {
                        $cacheInfo['cache_keys'][$processedCacheKey]['data'] = 'Error reading cache: ' . $e->getMessage();
                    }
                }
            } catch (\Throwable $e) {
                $cacheInfo['cache_error'] = $e->getMessage();
            }
        }

        return $cacheInfo;
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

        // Direct comparison - both are already 2-character language codes
        if ($this->primaryBrowserLanguage === $languageCode) {
            return true;
        }

        return false;
    }


    /**
     * Get cached domain mappings with server-side caching
     * Returns multi-dimensional array with pre-processed domain mappings
     */
    public function getCachedDomainMappings(string $salesChannelId, SalesChannelDomainCollection $salesChannelDomains): array
    {
        // Skip caching in non-production environments for testing
        if ($this->isNonProductionEnvironment()) {
            return $this->buildDomainMappings($salesChannelDomains);
        }

        // Create cache key for processed domain data
        $cacheKey = 'reqser_processed_domains_' . $salesChannelId;
        
        // If cache is available, use it
        if ($this->cache) {
            try {
                return $this->cache->get($cacheKey, function ($item) use ($salesChannelDomains) {
                    // Cache for the configured time - same as domain collection cache
                    $item->expiresAfter(self::CACHE_EXPIRATION_TIME);
                    
                    // Build processed mappings only when cache expires
                    return $this->buildDomainMappings($salesChannelDomains);
                });
                
            } catch (\Throwable $e) {
                // If cache fails, fall back to direct processing
                return $this->buildDomainMappings($salesChannelDomains);
            }
        }
        
        // No cache available - process directly
        return $this->buildDomainMappings($salesChannelDomains);
    }

    /**
     * Build the optimized domain mappings structure
     */
    private function buildDomainMappings(SalesChannelDomainCollection $salesChannelDomains): array
    {
        $redirectFromDomains = [];
        $redirectIntoDomains = [];
        $domainInformation = [];
        $domainLanguageCode = [];
        $languageIdToDomainId = [];

        foreach ($salesChannelDomains as $domain) {
            $domainId = $domain->getId();
            $domainCustomFields = $domain->getCustomFields();
            $redirectConfig = $this->customFieldService->getRedirectConfiguration($domainCustomFields);

            //Each Domain needs a languageCode as well as must be active 
            if (!$redirectConfig['active'] ?? false) {
                continue;
            } elseif (!$redirectConfig['languageCode'] ?? false) {
                continue;
            }
            
            // Check if domain has redirectFrom enabled
            if ($redirectConfig['redirectFrom'] ?? false) {
                $redirectFromDomains[] = $domainId;
            } elseif ($redirectConfig['redirectInto'] ?? false) {
                $redirectIntoDomains[] = $domainId;
            } else {
                continue;
            }

            $domainInformation[$domainId] = ['url' => $domain->getUrl(), 'redirectConfig' => $redirectConfig];
            $domainLanguageCode[$redirectConfig['languageCode']] = $domainId;
            $languageIdToDomainId[$domain->getLanguageId()] = $domainId;
        }

        return [
            'redirectFromDomains' => $redirectFromDomains,
            'redirectIntoDomains' => $redirectIntoDomains,
            'domainInformation' => $domainInformation,
            'domainLanguageCode' => $domainLanguageCode,
            'languageIdToDomainId' => $languageIdToDomainId
        ];
    }

    /**
     * Retrieve sales channel domains with ReqserRedirect custom fields by sales channel ID (cached method)
     * Caching is disabled in non-production environments for testing purposes
     */
    public function getSalesChannelDomainsById(string $salesChannelId): SalesChannelDomainCollection
    {
        // Skip caching in non-production environments for testing
        if ($this->isNonProductionEnvironment()) {
            return $this->queryDomainsByChannelId($salesChannelId);
        }

        // Create cache key based on sales channel ID
        $cacheKey = 'reqser_domains_' . $salesChannelId;
        
        // If cache is available, use it
        if ($this->cache) {
            try {
                return $this->cache->get($cacheKey, function ($item) use ($salesChannelId) {
                    // Cache for the configured time - domain configs rarely change
                    $item->expiresAfter(self::CACHE_EXPIRATION_TIME);
                    
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
     * Check if we're in a non-production environment (for testing purposes)
     * Disables caching in any environment that is not production
     * Uses globally stored environment from constructor injection
     */
    private function isNonProductionEnvironment(): bool
    {
        // Only enable caching in production environment
        return $this->environment !== 'prod';
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
