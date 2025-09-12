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
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

class ReqserLanguageRedirectService
{
    private $domainRepository;
    private $appService;
    private $webhookService;
    private $sessionService;
    private $customFieldService;
    private $redirectService;
    private $languageSwitchService;
    private $cache;
    private ?ControllerEvent $currentEvent = null;
    private ?array $currentCustomFields = null;
    private bool $debugMode = false;
    private bool $debugEchoMode = false;
    private ?array $redirectConfig = null;
    private ?string $primaryBrowserLanguage = null;
    private bool $sessionAvailable = false;
    private $salesChannelDomains = null;
    private ?string $salesChannelId = null;
    private $currentDomain = null;

    public function __construct(
        EntityRepository $domainRepository,
        ReqserAppService $appService,
        ReqserWebhookService $webhookService,
        ReqserSessionService $sessionService,
        ReqserCustomFieldService $customFieldService,
        ReqserRedirectService $redirectService,
        ReqserLanguageSwitchService $languageSwitchService,
        $cache
    ) {
        $this->domainRepository = $domainRepository;
        $this->appService = $appService;
        $this->webhookService = $webhookService;
        $this->sessionService = $sessionService;
        $this->customFieldService = $customFieldService;
        $this->redirectService = $redirectService;
        $this->languageSwitchService = $languageSwitchService;
        $this->cache = $cache;
    }

    /**
     * Initialize
     */
    public function initialize($session, array $redirectConfig, $request, $currentDomain, $salesChannelDomains, string $salesChannelId): void
    {
        $this->currentDomain = $currentDomain;
        $this->redirectConfig = $redirectConfig;
        $this->sessionAvailable = $this->sessionService->initialize($session, $this->redirectConfig);
        $this->salesChannelDomains = $salesChannelDomains;
        $this->salesChannelId = $salesChannelId;
        $this->primaryBrowserLanguage = $this->getPrimaryBrowserLanguage($request);
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
            $userLanguageSwitchIgnorePeriodS = $redirectConfig['userLanguageSwitchIgnorePeriodS'] ?? null;
            
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

        // Get the original page URL from the referer header
        $refererUrl = $_SERVER['HTTP_REFERER'] ?? null;
        
        if (!$refererUrl) {
            // No referer means we can't determine the original page
            return false;
        }

        // Get current domain URL (clean base URL)
        $currentDomainUrl = rtrim($this->currentDomain->getUrl(), '/');
        
        // Parse referer URL and remove query parameters and fragments
        $parsedReferer = parse_url($refererUrl);
        if (!$parsedReferer || !isset($parsedReferer['scheme']) || !isset($parsedReferer['host'])) {
            return false;
            }
        
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
     * Retrieve the new domain to redirect to based on browser language
     */
    public function retrieveNewDomainToRedirectTo(): ?string
    {
        // Get the primary browser language
        if (!$this->primaryBrowserLanguage) {
            return null;
        }

        // Find matching domain using database filtering for maximum performance
        $matchingDomain = $this->findMatchingDomainByLanguage();
        if ($matchingDomain) {
            return $matchingDomain->getUrl();
        }
        return null;
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
                if ($this->redirectService->isDomainValidForRedirectFromInto($domainConfig, $domain)) {
                    return $domain; // Early exit - return first match immediately
                }
            }
        }
        
        return null;
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
    private function userBrowserLanguageMatchesCurrentDomainLanguage(): bool
    {
        if ($this->primaryBrowserLanguage === null) {
            return true;
        }

        $languageCode = $this->redirectConfig['languageCode'];
        if ($languageCode === null) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Language code is null, we cannot check if the browser language matches this domain language', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            return true;
        }

        // Extract language code from locale (e.g., en-GB -> en)
        $language = strtolower(explode('-', $languageCode)[0]);
        
        if ($this->primaryBrowserLanguage == $language) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User browser language matches current domain language so we stop now to redirect', 'primaryBrowserLanguage' => $this->primaryBrowserLanguage, 'languageCode' => $languageCode, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
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
     * @param object $request Current request object
     */
    private function handleLanguageRedirect(string $preferred_browser_language, SalesChannelDomainCollection $salesChannelDomains, $currentDomain, $request): void
    {
        if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'All Sales Channel Domains to check', 'salesChannelDomains' => $salesChannelDomains, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
        foreach ($salesChannelDomains as $salesChannelDomain) {
            if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Checking sales channel domain', 'url' => $salesChannelDomain->url, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
            //If the current domain is the check we continue
            if ($currentDomain->getId() == $salesChannelDomain->getId()){
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Continue because it is the default domain', 'domain_id' => $salesChannelDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
                continue;
            } 
            // Only check domains within the same sales channel
            if ($salesChannelDomain->getSalesChannelId() != $currentDomain->getSalesChannelId()) {
                if ($this->debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Continue - different sales channel', 'url' => $salesChannelDomain->url, 'file' => __FILE__, 'line' => __LINE__], $this->debugEchoMode);
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
     * Retrieve sales channel domains with ReqserRedirect custom fields by sales channel ID (cached static method)
     */
    public static function getSalesChannelDomainsById(string $salesChannelId, ?EntityRepository $domainRepository = null, $cache = null)
    {
        // Create cache key based on sales channel ID
        $cacheKey = 'reqser_domains_' . $salesChannelId;
        
        // If cache is available, use it
        if ($cache) {
            try {
                return $cache->get($cacheKey, function ($item) use ($salesChannelId, $domainRepository) {
                    // Cache for 1 hour (3600 seconds) - domain configs rarely change
                    $item->expiresAfter(3600);
                    
                    // Query database only when cache expires
                    return self::queryDomainsByChannelId($salesChannelId, $domainRepository);
                });
                
            } catch (\Throwable $e) {
                // If cache fails, fall back to direct database query
                return self::queryDomainsByChannelId($salesChannelId, $domainRepository);
            }
        }
        
        // No cache available, query directly
        return self::queryDomainsByChannelId($salesChannelId, $domainRepository);
    }


    




    /**
     * Query domains from database by sales channel ID (static method)
     */
    private static function queryDomainsByChannelId(string $salesChannelId, ?EntityRepository $domainRepository = null)
    {
        if (!$domainRepository) {
            throw new \InvalidArgumentException('Domain repository is required for querying domains');
        }
        
        $criteria = new Criteria();
        
        // Ensure we're only retrieving domains that have the 'ReqserRedirect' custom field set
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.ReqserRedirect', null)
        ]));
        
        // Filter to current sales channel only (no cross-channel jumping)
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
    
        // Get the collection from the repository using default context
        return $domainRepository->search($criteria, Context::createDefaultContext())->getEntities();
    }
}
