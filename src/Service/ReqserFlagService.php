<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Simplified flag service - No app check, just flag utilities
 * Since template override is not active, flags always show via CSS
 */
class ReqserFlagService
{
    private KernelInterface $kernel;
    private ?bool $swagLanguagePackInstalled = null;

    public function __construct(KernelInterface $kernel)
    {
        $this->kernel = $kernel;
    }

    /**
     * Check if SwagLanguagePack is installed and active
     */
    public function isSwagLanguagePackInstalled(): bool
    {
        if ($this->swagLanguagePackInstalled !== null) {
            return $this->swagLanguagePackInstalled;
        }

        $bundles = $this->kernel->getBundles();
        $this->swagLanguagePackInstalled = isset($bundles['SwagLanguagePack']);

        return $this->swagLanguagePackInstalled;
    }

    /**
     * Get flag path with fallback logic (no app check)
     * 
     * @param string $localeCode Locale code like 'de-DE', 'en-GB', etc.
     * @return string Path to flag SVG
     */
    public function getFlagPath(string $localeCode): string
    {
        // Extract country code from locale (de-DE -> de, en-GB -> gb)
        $countryCode = strtolower(substr($localeCode, -2));

        // If SwagLanguagePack is installed, prefer its flags
        if ($this->isSwagLanguagePackInstalled()) {
            $swagPath = sprintf('bundles/swaglanguagepack/static/flags/%s.svg', $countryCode);
            if ($this->flagExists($swagPath)) {
                return $swagPath;
            }
        }

        // Fallback to plugin's own flags
        return sprintf('bundles/reqserplugin/static/flags/%s.svg', $countryCode);
    }

    /**
     * Get CSS class for flag with country code
     * 
     * @param string $localeCode Locale code like 'de-DE', 'en-GB', etc.
     * @return string CSS class like 'language-flag country-de'
     */
    public function getFlagClass(string $localeCode): string
    {
        $countryCode = strtolower(substr($localeCode, -2));
        return sprintf('language-flag country-%s', $countryCode);
    }

    /**
     * Check if a flag file exists in public bundles
     * 
     * @param string $relativePath Relative path from public directory
     * @return bool
     */
    private function flagExists(string $relativePath): bool
    {
        $projectDir = $this->kernel->getProjectDir();
        $fullPath = $projectDir . '/public/' . $relativePath;

        return file_exists($fullPath);
    }

    /**
     * Get all available country codes
     * 
     * @return array
     */
    public function getAvailableCountryCodes(): array
    {
        return [
            'ad', 'ae', 'af', 'ag', 'ai', 'al', 'am', 'ao', 'aq', 'ar', 'as', 'at', 'au', 'aw', 
            'ax', 'az', 'ba', 'bb', 'bd', 'be', 'bf', 'bg', 'bh', 'bi', 'bj', 'bl', 'bm', 'bn', 
            'bo', 'bq', 'br', 'bs', 'bt', 'bv', 'bw', 'by', 'bz', 'ca', 'cc', 'cd', 'cf', 'cg', 
            'ch', 'ci', 'ck', 'cl', 'cm', 'cn', 'co', 'cr', 'cu', 'cv', 'cw', 'cx', 'cy', 'cz', 
            'de', 'dj', 'dk', 'dm', 'do', 'dz', 'ec', 'ee', 'eg', 'eh', 'er', 'es', 'et', 'eu', 
            'fi', 'fj', 'fk', 'fm', 'fo', 'fr', 'ga', 'gb', 'gd', 'ge', 'gf', 'gg', 'gh', 'gi', 
            'gl', 'gm', 'gn', 'gp', 'gq', 'gr', 'gs', 'gt', 'gu', 'gw', 'gy', 'hk', 'hm', 'hn', 
            'hr', 'ht', 'hu', 'id', 'ie', 'il', 'im', 'in', 'io', 'iq', 'ir', 'is', 'it', 'je', 
            'jm', 'jo', 'jp', 'ke', 'kg', 'kh', 'ki', 'km', 'kn', 'kp', 'kr', 'kw', 'ky', 'kz', 
            'la', 'lb', 'lc', 'li', 'lk', 'lr', 'ls', 'lt', 'lu', 'lv', 'ly', 'ma', 'mc', 'md', 
            'me', 'mf', 'mg', 'mh', 'mk', 'ml', 'mm', 'mn', 'mo', 'mp', 'mq', 'mr', 'ms', 'mt', 
            'mu', 'mv', 'mw', 'mx', 'my', 'mz', 'na', 'nc', 'ne', 'nf', 'ng', 'ni', 'nl', 'no', 
            'np', 'nr', 'nu', 'nz', 'om', 'pa', 'pe', 'pf', 'pg', 'ph', 'pk', 'pl', 'pm', 'pn', 
            'pr', 'ps', 'pt', 'pw', 'py', 'qa', 're', 'ro', 'rs', 'ru', 'rw', 'sa', 'sb', 'sc', 
            'sd', 'se', 'sg', 'sh', 'si', 'sj', 'sk', 'sl', 'sm', 'sn', 'so', 'sr', 'ss', 'st', 
            'sv', 'sx', 'sy', 'sz', 'tc', 'td', 'tf', 'tg', 'th', 'tj', 'tk', 'tl', 'tm', 'tn', 
            'to', 'tr', 'tt', 'tv', 'tw', 'tz', 'ua', 'ug', 'um', 'un', 'us', 'uy', 'uz', 'va', 
            'vc', 've', 'vg', 'vi', 'vn', 'vu', 'wf', 'ws', 'xk', 'ye', 'yt', 'za', 'zm', 'zw'
        ];
    }
}
