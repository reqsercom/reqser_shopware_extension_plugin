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
     * Get float value from custom field
     */
    public function getFloat(?array $customFields, string $fieldName): ?float
    {
        $value = $this->getValue($customFields, $fieldName);
        return is_numeric($value) ? (float)$value : null;
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
     * Check if custom field exists
     */
    public function hasField(?array $customFields, string $fieldName): bool
    {
        return isset($customFields[self::CUSTOM_FIELD_PREFIX][$fieldName]);
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
        // Check if debug mode is enabled in domain configuration
        if (!($redirectConfig['debug_mode'] ?? false)) {
            return false;
        }

        // Check if debugModeIp is set and validate the request IP
        $debugModeIp = $redirectConfig['debug_mode_ip'] ?? null;
        if ($debugModeIp !== null) {
            $clientIp = $request->getClientIp();
            
            if ($clientIp == $debugModeIp) {
                // IP matches, activate debug mode
                $sessionValues = $sessionService ? $sessionService->getAllSessionData() : [];
                $debugEchoMode = $redirectConfig['debug_echo_mode'] ?? false;
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
            $debugEchoMode = $redirectConfig['debug_echo_mode'] ?? false;
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
    }

    /**
     * Get redirect configuration summary
     */
    public function getRedirectConfiguration(?array $customFields): array
    {
        return [
            // Basic redirect settings
            'advanced_redirect_enabled' => $this->getBool($customFields, 'advancedRedirectEnabled'),
            'javascript_redirect' => $this->getBool($customFields, 'javaScriptRedirect'),
            'jump_sales_channels' => $this->getBool($customFields, 'jumpSalesChannels'),
            'redirect_into' => $this->getBool($customFields, 'redirectInto'),
            
            // Page restrictions
            'only_redirect_front_page' => $this->getBool($customFields, 'onlyRedirectFrontPage'),
            'sanitize_url_on_front_page_check' => $this->getBool($customFields, 'sanatizeUrlOnFrontPageCheck'),
            
            // Language settings
            'redirect_on_default_browser_language_only' => $this->getBool($customFields, 'redirectOnDefaultBrowserLanguageOnly'),
            'language_redirect' => $this->getArray($customFields, 'languageRedirect'),
            
            // User override settings
            'user_override_enabled' => $this->getBool($customFields, 'userOverrideEnabled'),
            'redirect_if_user_override_domain_id_exists' => $this->getBool($customFields, 'redirectIfUserOverrideDomainIdExists'),
            'override_ignore_period_s' => $this->getInt($customFields, 'overrideIgnorePeriodS'),
            
            // Timing and limits
            'grace_period_ms' => $this->getInt($customFields, 'gracePeriodMs'),
            'block_period_ms' => $this->getInt($customFields, 'blockPeriodMs'),
            'max_redirects' => $this->getInt($customFields, 'maxRedirects'),
            'max_script_calls' => $this->getInt($customFields, 'maxScriptCalls'),
            
            // Debug settings
            'debug_mode' => $this->getBool($customFields, 'debugMode'),
            'debug_echo_mode' => $this->getBool($customFields, 'debugEchoMode'),
            'debug_mode_ip' => $this->getString($customFields, 'debugModeIp'),
        ];
    }

    /**
     * Check if echo mode is active (both debug mode and debug echo mode must be true)
     */
    public function isEchoModeActive(bool $debugMode, ?array $customFields): bool
    {
        return $debugMode && $this->getBool($customFields, 'debugEchoMode');
    }

    /**
     * Log configuration for debugging
     */
    public function logConfiguration(?array $customFields, $domainId): void
    {
        $debugMode = $this->getBool($customFields, 'debugMode');
        if ($debugMode) {
            $config = $this->getRedirectConfiguration($customFields);
            $validation = $this->validateConfiguration($customFields);
            $debugEchoMode = $redirectConfig['debug_echo_mode'] ?? false;
            
            $this->webhookService->sendErrorToWebhook([
                'type' => 'debug',
                'info' => 'Custom field configuration',
                'domain_id' => $domainId,
                'configuration' => $config,
                'validation' => $validation,
                'file' => __FILE__,
                'line' => __LINE__
            ], $debugEchoMode);
        }
    }
}
