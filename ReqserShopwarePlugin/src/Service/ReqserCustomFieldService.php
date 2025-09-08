<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Reqser\Plugin\Service\ReqserWebhookService;

class ReqserCustomFieldService
{
    private const CUSTOM_FIELD_PREFIX = 'ReqserRedirect';
    
    private $webhookService;

    public function __construct(ReqserWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Universal function to retrieve data from ReqserRedirect custom fields
     */
    public function getValue(?array $customFields, string $fieldName)
    {
        return $customFields[self::CUSTOM_FIELD_PREFIX][$fieldName] ?? null;
    }

    /**
     * Check if a ReqserRedirect custom field is set and true
     */
    public function getBool(?array $customFields, string $fieldName): bool
    {
        return $this->getValue($customFields, $fieldName) === true;
    }

    /**
     * Get string value from custom field
     */
    public function getString(?array $customFields, string $fieldName): ?string
    {
        $value = $this->getValue($customFields, $fieldName);
        return is_string($value) ? $value : null;
    }

    /**
     * Get integer value from custom field
     */
    public function getInt(?array $customFields, string $fieldName): ?int
    {
        $value = $this->getValue($customFields, $fieldName);
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * Get array value from custom field
     */
    public function getArray(?array $customFields, string $fieldName): ?array
    {
        $value = $this->getValue($customFields, $fieldName);
        return is_array($value) ? $value : null;
    }

    /**
     * Get all ReqserRedirect custom fields
     */
    public function getAllFields(?array $customFields): ?array
    {
        return $customFields[self::CUSTOM_FIELD_PREFIX] ?? null;
    }

    /**
     * Validate custom field configuration
     */
    public function validateConfiguration(?array $customFields): array
    {
        $errors = [];
        $warnings = [];

        $reqserFields = $this->getAllFields($customFields);
        if ($reqserFields === null) {
            $errors[] = 'No ReqserRedirect custom fields found';
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        // Validate timing configurations
        $gracePeriodMs = $this->getInt($customFields, 'gracePeriodMs');
        $blockPeriodMs = $this->getInt($customFields, 'blockPeriodMs');
        
        if ($gracePeriodMs !== null && $blockPeriodMs !== null) {
            if ($gracePeriodMs >= $blockPeriodMs) {
                $warnings[] = 'Grace period should be less than block period';
            }
        }

        // Validate redirect limits
        $maxRedirects = $this->getInt($customFields, 'maxRedirects');
        $maxScriptCalls = $this->getInt($customFields, 'maxScriptCalls');

        if ($maxRedirects !== null && $maxRedirects < 1) {
            $errors[] = 'Max redirects must be greater than 0';
        }

        if ($maxScriptCalls !== null && $maxScriptCalls < 1) {
            $errors[] = 'Max script calls must be greater than 0';
        }

        // Validate user override period
        $overrideIgnorePeriodS = $this->getInt($customFields, 'overrideIgnorePeriodS');
        if ($overrideIgnorePeriodS !== null && $overrideIgnorePeriodS < 0) {
            $errors[] = 'Override ignore period cannot be negative';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Check if debug mode is active based on configuration
     * This is the ONLY place where debug mode is determined - it's based on custom fields, not hardcoded
     */
    public function isDebugModeActive(?array $redirectConfig, $request, $currentDomain, $sessionService = null): bool
    {
        try {
            // Check if debug mode is enabled in domain configuration
            if (!($redirectConfig['debugMode'] ?? false)) {
                return false;
            }

            // Check if debugModeIp is set and validate the request IP
            $debugModeIp = $redirectConfig['debugModeIp'] ?? null;
            if ($debugModeIp !== null) {
                $clientIp = $request->getClientIp();
                
                if ($clientIp == $debugModeIp) {
                    // IP matches, activate debug mode
                    $sessionValues = $sessionService ? $sessionService->getAllSessionData() : [];
                    $debugEchoMode = $redirectConfig['debugEchoMode'] ?? false;
                    $this->webhookService->sendErrorToWebhook([
                        'type' => 'debug', 
                        'info' => 'Debug mode activated - IP match', 
                        'clientIp' => $clientIp, 
                        'debugModeIp' => $debugModeIp, 
                        'sessionValues' => $sessionValues,
                        'domain_id' => $currentDomain->getId(), 
                        'file' => __FILE__, 
                        'line' => __LINE__
                    ], $debugEchoMode);
                    return true;
                } else {
                    // IP doesn't match, debug mode is not active
                    return false;
                }
            } else {
                // No IP restriction, activate debug mode
                $sessionValues = $sessionService ? $sessionService->getAllSessionData() : [];
                $debugEchoMode = $redirectConfig['debugEchoMode'] ?? false;
                $this->webhookService->sendErrorToWebhook([
                    'type' => 'debug', 
                    'info' => 'Debug mode activated - no IP restriction', 
                    'sessionValues' => $sessionValues,
                    'domain_id' => $currentDomain->getId(), 
                    'file' => __FILE__, 
                    'line' => __LINE__
                ], $debugEchoMode);
                return true;
            }
        } catch (\Throwable $e) {
            $this->webhookService->sendErrorToWebhook([
                'type' => 'error', 
                'info' => 'isDebugModeActive() Error', 
                'message' => $e->getMessage(), 
                'trace' => $e->getTraceAsString(), 
                'domain_id' => $currentDomain->getId(), 
                'file' => __FILE__, 
                'line' => __LINE__
            ]);
            return false;
        }
        
    }

    /**
     * Get redirect configuration summary
     */
    public function getRedirectConfiguration(?array $customFields): array
    {
        return [
            // Basic redirect settings
            'active' => $this->getBool($customFields, 'active'),
            'redirectFrom' => $this->getBool($customFields, 'redirectFrom'),
            'advancedRedirectEnabled' => $this->getBool($customFields, 'advancedRedirectEnabled'),
            'javaScriptRedirect' => $this->getBool($customFields, 'javaScriptRedirect'),
            'jumpSalesChannels' => $this->getBool($customFields, 'jumpSalesChannels'),
            'redirectInto' => $this->getBool($customFields, 'redirectInto'),
            
            // Page restrictions
            'onlyRedirectFrontPage' => $this->getBool($customFields, 'onlyRedirectFrontPage'),
            'sanatizeUrlOnFrontPageCheck' => $this->getBool($customFields, 'sanatizeUrlOnFrontPageCheck'),
            
            // Language settings
            'languageCode' => $this->getString($customFields, 'languageCode'),
            'redirectOnDefaultBrowserLanguageOnly' => $this->getBool($customFields, 'redirectOnDefaultBrowserLanguageOnly'),
            'languageRedirect' => $this->getArray($customFields, 'languageRedirect'),
            
            // User override settings
            'userOverrideEnabled' => $this->getBool($customFields, 'userOverrideEnabled'),
            'redirectBasedOnUserLanguageSwitch' => $this->getBool($customFields, 'redirectBasedOnUserLanguageSwitch'),
            'overrideIgnorePeriodS' => $this->getInt($customFields, 'overrideIgnorePeriodS'),
            'skipRedirectAfterManualLanguageSwitch' => $this->getBool($customFields, 'skipRedirectAfterManualLanguageSwitch'),
            'redirectToUserPreviouslyChosenDomain' => $this->getBool($customFields, 'redirectToUserPreviouslyChosenDomain'),
            
            // Session settings
            'sessionIgnoreMode' => $this->getBool($customFields, 'sessionIgnoreMode'),
            'onlyRedirectIfSessionIsAvailable' => $this->getBool($customFields, 'onlyRedirectIfSessionIsAvailable'),
            
            // Timing and limits
            'gracePeriodMs' => $this->getInt($customFields, 'gracePeriodMs'),
            'blockPeriodMs' => $this->getInt($customFields, 'blockPeriodMs'),
            'maxRedirects' => $this->getInt($customFields, 'maxRedirects'),
            'maxScriptCalls' => $this->getInt($customFields, 'maxScriptCalls'),
            
            // Debug settings
            'debugMode' => $this->getBool($customFields, 'debugMode'),
            'debugEchoMode' => $this->getBool($customFields, 'debugEchoMode'),
            'debugModeIp' => $this->getString($customFields, 'debugModeIp'),
        ];
    }

    /**
     * Check if debug echo mode is active (requires debug mode to be active first)
     */
    public function isDebugEchoModeActive(bool $debugMode, ?array $redirectConfig): bool
    {
        // If debug mode is not active, echo mode cannot be active
        if (!$debugMode) {
            return false;
        }
        
        // Check if debug echo mode is enabled in configuration
        return $this->getBool($redirectConfig, 'debugEchoMode');
    }

}
