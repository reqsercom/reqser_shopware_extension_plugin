<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

class ReqserCustomFieldService
{
    public const CUSTOM_FIELD_PREFIX = 'ReqserRedirect';
    public const SALES_AGENT_PREFIX = 'ReqserSalesAgent';

    /**
     * Universal function to retrieve data from a Reqser custom field group
     *
     * @param ?array $customFields
     * @param string $fieldName
     * @param string $prefix
     */
    public function getValue(?array $customFields, string $fieldName, string $prefix = self::CUSTOM_FIELD_PREFIX)
    {
        return $customFields[$prefix][$fieldName] ?? null;
    }

    /**
     * Check if a Reqser custom field is set and true
     *
     * @param ?array $customFields
     * @param string $fieldName
     * @param string $prefix
     * @return bool
     */
    public function getBool(?array $customFields, string $fieldName, string $prefix = self::CUSTOM_FIELD_PREFIX): bool
    {
        return $this->getValue($customFields, $fieldName, $prefix) === true;
    }

    /**
     * Get string value from custom field
     *
     * @param ?array $customFields
     * @param string $fieldName
     * @param string $prefix
     * @return ?string
     */
    public function getString(?array $customFields, string $fieldName, string $prefix = self::CUSTOM_FIELD_PREFIX): ?string
    {
        $value = $this->getValue($customFields, $fieldName, $prefix);
        return is_string($value) ? $value : null;
    }

    /**
     * Get integer value from custom field
     *
     * @param ?array $customFields
     * @param string $fieldName
     * @param string $prefix
     * @return ?int
     */
    public function getInt(?array $customFields, string $fieldName, string $prefix = self::CUSTOM_FIELD_PREFIX): ?int
    {
        $value = $this->getValue($customFields, $fieldName, $prefix);
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * Get array value from custom field
     *
     * @param ?array $customFields
     * @param string $fieldName
     * @param string $prefix
     * @return ?array
     */
    public function getArray(?array $customFields, string $fieldName, string $prefix = self::CUSTOM_FIELD_PREFIX): ?array
    {
        $value = $this->getValue($customFields, $fieldName, $prefix);
        return is_array($value) ? $value : null;
    }

    /**
     * Get all custom fields of a Reqser group
     *
     * @param ?array $customFields
     * @param string $prefix
     * @return ?array
     */
    public function getAllFields(?array $customFields, string $prefix = self::CUSTOM_FIELD_PREFIX): ?array
    {
        return $customFields[$prefix] ?? null;
    }



    /**
     * Get simplified redirect-into configuration for domains that only need basic validation
     * Only returns: active, redirectInto, and languageCode
     *
     * @param ?array $customFields
     * @return array
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
     *
     * @param ?array $customFields
     * @return array
     */
    public function getRedirectConfiguration(?array $customFields): array
    {
        $active = $this->getBool($customFields, 'active');
        if (!$active) {
            return [
                'active' => false,
            ];
        }

        //Default Config if active
        $config = [
            'active' => $active,
            'languageCode' => $this->getString($customFields, 'languageCode'),
            'extendDebugInformation' => $this->getBool($customFields, 'extendDebugInformation'),
        ];

        $redirectInto = $this->getBool($customFields, 'redirectInto');
        if ($redirectInto) {
            //Security Check, it can not be true on both as this could lead to redirect loops!
            $redirectFrom = $this->getBool($customFields, 'redirectFrom');
            if ($redirectFrom === false) {
                $config['redirectInto'] = $redirectInto;
            } else {
                $config['redirectInto'] = false;
            }
            return $config;
        }

        $redirectFrom = $this->getBool($customFields, 'redirectFrom');
        if ($redirectFrom) {
            return 
                array_merge($config, [
                'redirectFrom' => $redirectFrom,
                'skipRedirectAfterManualLanguageSwitch' => $this->getBool($customFields, 'skipRedirectAfterManualLanguageSwitch'),
                'userLanguageSwitchIgnorePeriodS' => $this->getInt($customFields, 'userLanguageSwitchIgnorePeriodS'),
                'redirectToUserPreviouslyChosenDomain' => $this->getBool($customFields, 'redirectToUserPreviouslyChosenDomain'),
                'redirectToAlternativeLanguage' => $this->getBool($customFields, 'redirectToAlternativeLanguage'),
                'alternativeRedirectLanguageCode' => $this->getString($customFields, 'alternativeRedirectLanguageCode'),
                'sessionIgnoreMode' => $this->getBool($customFields, 'sessionIgnoreMode'),
                'gracePeriodMs' => $this->getInt($customFields, 'gracePeriodMs'),
                'blockPeriodMs' => $this->getInt($customFields, 'blockPeriodMs'),
                'maxRedirects' => $this->getInt($customFields, 'maxRedirects'),
                'maxScriptCalls' => $this->getInt($customFields, 'maxScriptCalls'),
                'preserveUrlParameters' => $this->getBool($customFields, 'preserveUrlParameters')]);
        }

        //Fallback
        return [
            'active' => false,
        ];
    }


}
