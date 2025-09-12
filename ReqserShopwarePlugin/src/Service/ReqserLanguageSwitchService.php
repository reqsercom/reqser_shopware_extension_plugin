<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserSessionService;
use Reqser\Plugin\Service\ReqserCustomFieldService;
use Reqser\Plugin\Service\ReqserRedirectService;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;

class ReqserLanguageSwitchService
{
    private $webhookService;
    private $sessionService;
    private $customFieldService;
    private $redirectService;

    public function __construct(
        ReqserWebhookService $webhookService,
        ReqserSessionService $sessionService,
        ReqserCustomFieldService $customFieldService,
        ReqserRedirectService $redirectService
    ) {
        $this->webhookService = $webhookService;
        $this->sessionService = $sessionService;
        $this->customFieldService = $customFieldService;
        $this->redirectService = $redirectService;
    }

    /**
     * Check for manual language switch events and handle accordingly
     * This is the main entry point for all language switch related logic
     */
    public function checkForManualLanguageSwitchEvent(
        $currentDomain, 
        SalesChannelDomainCollection $salesChannelDomains, 
        array $redirectConfig,
        bool $debugMode,
        bool $debugEchoMode,
        $currentEvent
    ): bool {

        $userOverrideEnabled = $redirectConfig['userOverrideEnabled'] ?? false;

        if ($userOverrideEnabled === true) {
            // First check if we should skip due to user override (user manually switched language recently)
            $skipRedirectAfterManualLanguageSwitch = $redirectConfig['skipRedirectAfterManualLanguageSwitch'] ?? false;
            if ($skipRedirectAfterManualLanguageSwitch === true) {
                if ($this->shouldSkipDueToUserOverride($redirectConfig, $debugMode, $debugEchoMode, $currentDomain)) {
                    if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Skipping redirect - user override active', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $debugEchoMode);
                    return false; // Skip redirect
                }
            }
            // Then check if we should redirect to user's previously chosen domain
            $redirectToUserPreviouslyChosenDomain = $redirectConfig['redirectToUserPreviouslyChosenDomain'] ?? false;
            if ($redirectToUserPreviouslyChosenDomain === true) {
                if ($this->handleUserOverrideLanguageRedirect($currentDomain, $salesChannelDomains, $redirectConfig, $debugMode, $debugEchoMode, $currentEvent)) {
                    if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User override language redirect handled', 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $debugEchoMode);
                    return false; // Redirect was handled, stop normal flow
                }
            }
        }

        return true; // No language switch event, continue with normal flow
    }

    /**
     * Check if redirect should be skipped due to user override settings
     */
    public function shouldSkipDueToUserOverride(array $redirectConfig, bool $debugMode, bool $debugEchoMode, $currentDomain): bool
    {
        if ($this->sessionService->getUserlanguageSwitchTimestamp()) {
            $languageSwitchTimestamp = $this->sessionService->getUserlanguageSwitchTimestamp();
            $userLanguageSwitchIgnorePeriodS = $redirectConfig['userLanguageSwitchIgnorePeriodS'] ?? null;
            
            if ($userLanguageSwitchIgnorePeriodS !== null) {
                // Check if the override timestamp is younger than the userLanguageSwitchIgnorePeriodS
                if ($languageSwitchTimestamp > time() - $userLanguageSwitchIgnorePeriodS) {
                    if ($debugMode) {
                        $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User override active - within ignore period', 'languageSwitchTimestamp' => $languageSwitchTimestamp, 'userLanguageSwitchIgnorePeriodS' => $userLanguageSwitchIgnorePeriodS, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $debugEchoMode);
                    }
                    return true; // Skip redirect
                }
            } else {
                if ($debugMode) {
                    $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'User override active - no period restriction', 'languageSwitchTimestamp' => $languageSwitchTimestamp, 'domain_id' => $currentDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $debugEchoMode);
                }
                return true; // Skip redirect (no period restriction)
            }
        }
        
        return false; // Don't skip redirect
    }

    /**
     * Handle user override language redirect based on stored domain ID
     * If the user has chosen via Language Switch a domain, we will ignore browser language and redirect to this domain
     */
    private function handleUserOverrideLanguageRedirect(
        $currentDomain, 
        SalesChannelDomainCollection $salesChannelDomains, 
        array $redirectConfig,
        bool $debugMode,
        bool $debugEchoMode,
        $currentEvent
    ): bool {
        // Get the stored language ID from session
        $sessionLanguageId = $this->sessionService->getUserOverrideLanguageId();
     
        // Check if session language ID exists and if it matches current domain language
        if ($sessionLanguageId) {
            if ($sessionLanguageId === $currentDomain->getLanguageId()) {
                // User wants to stay on current language/domain - no redirect needed
                return true;
            } else {
                // Find domain that matches the target language ID
                $targetDomain = null;
                foreach ($salesChannelDomains as $domain) {
                    if ($domain->getLanguageId() === $sessionLanguageId) {
                        $targetDomainConfig = $this->customFieldService->getRedirectConfiguration($domain->getCustomFields());
                        if (!$this->redirectService->isDomainValidForRedirectFromInto($targetDomainConfig, $targetDomain)) {
                            continue;
                        } else {
                            $targetDomain = $domain;
                            if ($debugMode) $this->webhookService->sendErrorToWebhook(['type' => 'debug', 'info' => 'Target Domain Found based on last manual language switch', 'domain_id' => $targetDomain->getId(), 'file' => __FILE__, 'line' => __LINE__], $debugEchoMode);
                            break;
                        }
                    }
                }
                
                if ($targetDomain) {
                    $this->redirectService->handleDirectDomainRedirect(
                        $targetDomain->getUrl(),
                        ($redirectConfig['javaScriptRedirect'] ?? false),
                        $currentEvent
                    );
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check for manual language switch events for AJAX requests (simplified version)
     */
    public function checkForManualLanguageSwitchEventForAjax(
        $currentDomain, 
        SalesChannelDomainCollection $salesChannelDomains, 
        array $redirectConfig
    ): bool {
        $userOverrideEnabled = $redirectConfig['userOverrideEnabled'] ?? false;

        if ($userOverrideEnabled === true) {
            $skipRedirectAfterManualLanguageSwitch = $redirectConfig['skipRedirectAfterManualLanguageSwitch'] ?? false;
            if ($skipRedirectAfterManualLanguageSwitch === true) {
                if ($this->shouldSkipDueToUserOverride($redirectConfig, false, false, $currentDomain)) {
                    return false; // Skip redirect
                }
            }
        }

        return true; // No language switch event, continue with normal flow
    }
}
