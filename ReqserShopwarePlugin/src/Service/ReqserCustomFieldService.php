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
     * Get simplified redirect-into configuration for domains that only need basic validation
     * Only returns: active, redirectInto, and languageCode
     */
    public function getRedirectIntoConfiguration(?array $customFields): array
    {
        return [
            'active' => $this->getBool($customFields, 'active'),
            'redirectInto' => $this->getBool($customFields, 'redirectInto'),
            'languageCode' => $this->getString($customFields, 'languageCode'),
        ];
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
            'redirectInto' => $this->getBool($customFields, 'redirectInto'),
            
            // Page restrictions
            'onlyRedirectFrontPage' => $this->getBool($customFields, 'onlyRedirectFrontPage'),
            
            // Language settings
            'languageCode' => $this->getString($customFields, 'languageCode'),
            
            // User language switch settings
            'skipRedirectAfterManualLanguageSwitch' => $this->getBool($customFields, 'skipRedirectAfterManualLanguageSwitch'),
            'userLanguageSwitchIgnorePeriodS' => $this->getInt($customFields, 'userLanguageSwitchIgnorePeriodS'),
            'redirectToUserPreviouslyChosenDomain' => $this->getBool($customFields, 'redirectToUserPreviouslyChosenDomain'),
            
            // Session settings
            'sessionIgnoreMode' => $this->getBool($customFields, 'sessionIgnoreMode'),
            
            // Timing and limits
            'gracePeriodMs' => $this->getInt($customFields, 'gracePeriodMs'),
            'blockPeriodMs' => $this->getInt($customFields, 'blockPeriodMs'),
            'maxRedirects' => $this->getInt($customFields, 'maxRedirects'),
            'maxScriptCalls' => $this->getInt($customFields, 'maxScriptCalls'),
            
            // URL parameter settings
            'preserveUrlParameters' => $this->getBool($customFields, 'preserveUrlParameters'),
        ];
    }


}
