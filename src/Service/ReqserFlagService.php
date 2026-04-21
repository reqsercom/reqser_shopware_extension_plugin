<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Flag service providing flag utilities for the storefront language switcher.
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
        $countryCode = strtolower(substr($localeCode, -2));

        if ($this->isSwagLanguagePackInstalled()) {
            $swagPath = sprintf('bundles/swaglanguagepack/static/flags/%s.svg', $countryCode);
            if ($this->flagExists($swagPath)) {
                return $swagPath;
            }
        }

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
}
