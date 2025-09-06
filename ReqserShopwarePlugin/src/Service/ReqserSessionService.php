<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Reqser\Plugin\Service\ReqserWebhookService;

class ReqserSessionService
{
    private $session = null;
    private ?int $redirectCount = null;
    private bool $sessionIgnoreMode = false;
    private $webhookService;
    private ?array $redirectConfig = null;

    public function __construct(ReqserWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Initialize the service with a session and redirect configuration
     */
    public function initialize($session, ?array $redirectConfig = null): void
    {
        $this->session = $session;
        $this->redirectConfig = $redirectConfig;
        $this->redirectCount = null; // Reset cache when session changes
    }

    /**
     * Set session ignore mode
     */
    public function setSessionIgnoreMode(bool $ignoreMode): void
    {
        $this->sessionIgnoreMode = $ignoreMode;
    }

    /**
     * Check if redirect was already called
     */
    public function isRedirectCalled(): bool
    {
        return $this->session->get('reqser_redirect_called', false);
    }

    /**
     * Set redirect called flag
     */
    public function setRedirectCalled(bool $called = true): void
    {
        $this->session->set('reqser_redirect_called', $called);
    }

    /**
     * Get last redirect timestamp
     */
    public function getLastRedirectTime(): ?float
    {
        return $this->session->get('reqser_last_redirect_at', null);
    }

    /**
     * Set last redirect timestamp
     */
    public function setLastRedirectTime(?float $timestamp = null): void
    {
        if ($timestamp === null) {
            $timestamp = microtime(true) * 1000;
        }
        $this->session->set('reqser_last_redirect_at', $timestamp);
    }

    /**
     * Get last redirect timestamp
     */
    public function getLastRedirectAt(): ?float
    {
        return $this->session->get('reqser_last_redirect_at', null);
    }

    /**
     * Get redirect count from session and cache it
     */
    public function getRedirectCount(): int
    {
        if ($this->redirectCount === null) {
            $this->redirectCount = $this->session->get('reqser_redirect_count', 0);
        }
        return $this->redirectCount;
    }

    /**
     * Increment and store redirect count
     */
    public function incrementRedirectCount(): int
    {
        $redirectCount = $this->getRedirectCount() + 1;
        $this->redirectCount = $redirectCount;
        $this->session->set('reqser_redirect_count', $redirectCount);
        return $redirectCount;
    }

    /**
     * Get script call count
     */
    public function getScriptCallCount(): int
    {
        return $this->session->get('reqser_script_call_count', 0);
    }

    /**
     * Increment script call count
     */
    public function incrementScriptCallCount(?int $currentCount = null): int
    {
        if ($currentCount === null) {
            $currentCount = $this->getScriptCallCount();
        }
        $scriptCallCount = $currentCount + 1;
        $this->session->set('reqser_script_call_count', $scriptCallCount);
        return $scriptCallCount;
    }

    /**
     * Get user override timestamp
     */
    public function getUserOverrideTimestamp(): ?int
    {
        return $this->session->get('reqser_redirect_user_override_timestamp', null);
    }

    /**
     * Set user override timestamp
     */
    public function setUserOverrideTimestamp(?int $timestamp = null): void
    {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $this->session->set('reqser_redirect_user_override_timestamp', $timestamp);
    }

    /**
     * Check if user override timestamp exists
     */
    public function hasUserOverrideTimestamp(): bool
    {
        return $this->session->get('reqser_redirect_user_override_timestamp', false) !== false;
    }

    /**
     * Get user override domain ID
     */
    public function getUserOverrideDomainId(): ?string
    {
        return $this->session->get('reqser_user_override_domain_id', null);
    }

    /**
     * Set user override domain ID
     */
    public function setUserOverrideDomainId(?string $domainId): void
    {
        $this->session->set('reqser_user_override_domain_id', $domainId);
    }

    /**
     * Prepare session variables before redirecting
     */
    public function prepareRedirectSession(): void
    {
        // Only write session data if sessionIgnoreMode is false
        if ($this->sessionIgnoreMode === false) {
            $this->setLastRedirectTime();
            $this->incrementRedirectCount();
        }
    }

    /**
     * Get all session data (for debugging)
     */
    public function getAllSessionData(): array
    {
        return $this->session->all();
    }

    /**
     * Clear all redirect-related session data
     */
    public function clearRedirectSessionData(): void
    {
        $this->session->remove('reqser_redirect_called');
        $this->session->remove('reqser_last_redirect_at');
        $this->session->remove('reqser_redirect_count');
        $this->session->remove('reqser_script_call_count');
        $this->session->remove('reqser_redirect_user_override_timestamp');
        $this->session->remove('reqser_user_override_domain_id');
        
        // Reset cache
        $this->redirectCount = null;
    }

    /**
     * Get session statistics for debugging
     */
    public function getSessionStats(): array
    {
        return [
            'redirect_called' => $this->isRedirectCalled(),
            'last_redirect_time' => $this->getLastRedirectTime(),
            'redirect_count' => $this->getRedirectCount(),
            'script_call_count' => $this->getScriptCallCount(),
            'user_override_timestamp' => $this->getUserOverrideTimestamp(),
            'user_override_domain_id' => $this->getUserOverrideDomainId(),
            'session_ignore_mode' => $this->sessionIgnoreMode,
        ];
    }

    /**
     * Check if redirect should be skipped due to user override settings
     */
    public function shouldSkipDueToUserOverride(): bool
    {
        $userOverrideEnabled = $this->redirectConfig['userOverrideEnabled'] ?? false;
        $advancedRedirectEnabled = $this->redirectConfig['advancedRedirectEnabled'] ?? false;
        
        // Check if user override conditions are met
        if ($userOverrideEnabled === true && $advancedRedirectEnabled === true && $this->getUserOverrideTimestamp()) {
            $overrideTimestamp = $this->getUserOverrideTimestamp();
            $overrideIgnorePeriodS = $this->redirectConfig['overrideIgnorePeriodS'] ?? null;
            
            if ($overrideIgnorePeriodS !== null) {
                // Check if the override timestamp is younger than the overrideIgnorePeriodS
                if ($overrideTimestamp > time() - $overrideIgnorePeriodS) {
                    return true; // Skip redirect
                }
            } else {
                return true; // Skip redirect (no period restriction)
            }
        }
        
        return false; // Don't skip redirect
    }

    /**
     * Validate and manage session redirects
     */
    public function validateAndManageSessionRedirects(): bool
    {
        $advancedRedirectEnabled = $this->redirectConfig['advancedRedirectEnabled'] ?? false;
        $sessionIgnoreMode = $this->redirectConfig['sessionIgnoreMode'] ?? false;
        $redirectCount = $this->getRedirectCount();
        
        if ($redirectCount > 0 && $advancedRedirectEnabled === true && $sessionIgnoreMode === false) {
            // Set redirect done flag
            $this->prepareRedirectSession();
            $lastRedirectTime = $this->getLastRedirectAt();
            
            $gracePeriodMs = $this->redirectConfig['gracePeriodMs'] ?? null;
            $blockPeriodMs = $this->redirectConfig['blockPeriodMs'] ?? null;
            $maxRedirects = $this->redirectConfig['maxRedirects'] ?? null;
            $maxScriptCalls = $this->redirectConfig['maxScriptCalls'] ?? null;
            
            if ($maxRedirects === null && $gracePeriodMs === null && $blockPeriodMs === null && $maxScriptCalls === null) {
                return true;
            }
            
            if ($maxRedirects !== null && $redirectCount >= $maxRedirects) {
                return false;
            }

            $scriptCallCount = $this->getScriptCallCount();

            if ($maxScriptCalls !== null && $scriptCallCount >= $maxScriptCalls) {
                return false;
            }

            // Update script call count for validation tracking
            $this->incrementScriptCallCount();

            // Check if the last redirect was done after the grace period but within the block period
            $currentTimestamp = microtime(true) * 1000;
            if ($gracePeriodMs !== null && $lastRedirectTime !== null && 
                $lastRedirectTime < $currentTimestamp - $gracePeriodMs && 
                $lastRedirectTime > $currentTimestamp - $blockPeriodMs) {
                return false;
            }
        } 

        return true; 
    }

    /**
     * Check if user has overridden to a specific domain and should redirect there
     */
    public function shouldRedirectToUserOverrideDomain($currentDomainId, $salesChannelDomains): ?string
    {
        // Get the stored domain ID from session
        $sessionDomainId = $this->getUserOverrideDomainId();
        
        // Check if session domain ID exists and matches current domain ID
        if ($sessionDomainId) {
            if ($sessionDomainId === $currentDomainId) {
                return null; // Already on the right domain
            } else {
                // Check if the domain is in the sales channel domains
                $sessionDomain = $salesChannelDomains->get($sessionDomainId);
                if ($sessionDomain) {
                    return $sessionDomainId; // Return domain ID to redirect to
                }
            }
        }
        
        return null; // No redirect needed
    }

}
