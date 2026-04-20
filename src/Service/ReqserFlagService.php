<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Flag service providing flag utilities and language switcher flag overrides
 * via ReqserLanguageSwitch custom fields on sales channel domains.
 */
class ReqserFlagService
{
    private const LANGUAGE_SWITCH_PREFIX = 'ReqserLanguageSwitch';
    private const CACHE_EXPIRATION_TIME = 3600;

    private KernelInterface $kernel;
    private EntityRepository $domainRepository;
    private CacheInterface $cache;
    private string $environment;
    private ?bool $swagLanguagePackInstalled = null;

    /** @var array<string, array<string, string>> In-memory cache per sales channel ID */
    private array $flagOverrideCache = [];

    public function __construct(
        KernelInterface $kernel,
        EntityRepository $domainRepository,
        CacheInterface $cache,
        string $environment
    ) {
        $this->kernel = $kernel;
        $this->domainRepository = $domainRepository;
        $this->cache = $cache;
        $this->environment = $environment;
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

    /**
     * Get flag country code overrides for a sales channel.
     * Returns a map of languageId => flagCountryCode for domains that have
     * a ReqserLanguageSwitch.flagCountryCode custom field set.
     *
     * Uses server-side cache (cache.app) in production to avoid DAL queries on
     * every storefront page request. Caching is disabled in non-production
     * environments for testing, matching ReqserLanguageRedirectService.
     *
     * @param string $salesChannelId
     * @param Context $context
     * @return array<string, string> Map of languageId => 2-letter country code
     */
    public function getFlagOverrides(string $salesChannelId, Context $context): array
    {
        if (isset($this->flagOverrideCache[$salesChannelId])) {
            return $this->flagOverrideCache[$salesChannelId];
        }

        if ($this->isNonProductionEnvironment()) {
            $result = $this->queryFlagOverrides($salesChannelId, $context);
            $this->flagOverrideCache[$salesChannelId] = $result;
            return $result;
        }

        $cacheKey = 'reqser_flag_overrides_' . $salesChannelId;

        try {
            $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($salesChannelId, $context) {
                $item->expiresAfter(self::CACHE_EXPIRATION_TIME);
                return $this->queryFlagOverrides($salesChannelId, $context);
            });
        } catch (\Throwable $e) {
            $result = $this->queryFlagOverrides($salesChannelId, $context);
        }

        $this->flagOverrideCache[$salesChannelId] = $result;

        return $result;
    }

    /**
     * Query DAL for flag overrides on a given sales channel.
     *
     * @param string $salesChannelId
     * @param Context $context
     * @return array<string, string>
     */
    private function queryFlagOverrides(string $salesChannelId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannel.id', $salesChannelId));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.' . self::LANGUAGE_SWITCH_PREFIX, null)
        ]));

        $domains = $this->domainRepository->search($criteria, $context)->getEntities();

        $overrides = [];
        foreach ($domains as $domain) {
            $customFields = $domain->getCustomFields();
            $flagCode = $customFields[self::LANGUAGE_SWITCH_PREFIX]['flagCountryCode'] ?? null;
            if ($flagCode !== null && is_string($flagCode)) {
                $overrides[$domain->getLanguageId()] = strtolower($flagCode);
            }
        }

        return $overrides;
    }

    /**
     * Check if we're in a non-production environment.
     * Disables caching in any environment that is not production,
     * matching ReqserLanguageRedirectService behavior.
     */
    private function isNonProductionEnvironment(): bool
    {
        return $this->environment !== 'prod';
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
