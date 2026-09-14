<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reports the sales agent widget configuration for the domain a storefront request was resolved to.
 */
class ReqserSalesAgentService
{
    // Cache expiration time in seconds (1 hour)
    private const CACHE_EXPIRATION_TIME = 3600;

    private const DEFAULT_WIDGET_ORIGIN = 'https://reqser.com';

    private $domainRepository;
    private $appService;
    private $customFieldService;
    private $cache;
    private string $environment;

    /**
     * @param EntityRepository $domainRepository
     * @param ReqserAppService $appService
     * @param ReqserCustomFieldService $customFieldService
     * @param mixed $cache
     * @param string $environment
     */
    public function __construct(
        EntityRepository $domainRepository,
        ReqserAppService $appService,
        ReqserCustomFieldService $customFieldService,
        $cache,
        string $environment
    ) {
        $this->domainRepository = $domainRepository;
        $this->appService = $appService;
        $this->customFieldService = $customFieldService;
        $this->cache = $cache;
        $this->environment = $environment;
    }

    /**
     * Resolve the widget configuration for a storefront request
     *
     * Returns null whenever the agent must stay inert: app inactive, no resolved domain,
     * or the domain is not activated.
     *
     * @param Request $request
     * @return ?array{widgetOrigin: string, domainId: string}
     */
    public function getWidgetConfig(Request $request): ?array
    {
        if (!$this->appService->isAppActive()) {
            return null;
        }

        $domainId = $request->attributes->get(SalesChannelRequest::ATTRIBUTE_DOMAIN_ID);
        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        if (!is_string($domainId) || $domainId === '' || !is_string($salesChannelId) || $salesChannelId === '') {
            return null;
        }

        $configByDomain = $this->getCachedDomainConfigs($salesChannelId);

        return $configByDomain[$domainId] ?? null;
    }

    /**
     * Get the widget configuration per domain id, cached per sales channel
     *
     * @param string $salesChannelId
     * @return array<string, array{widgetOrigin: string, domainId: string}>
     */
    private function getCachedDomainConfigs(string $salesChannelId): array
    {
        // Skip caching in non-production environments for testing
        if ($this->isNonProductionEnvironment()) {
            return $this->buildDomainConfigs($salesChannelId);
        }

        $cacheKey = 'reqser_sales_agent_domains_' . $salesChannelId;

        if ($this->cache) {
            try {
                return $this->cache->get($cacheKey, function ($item) use ($salesChannelId) {
                    $item->expiresAfter(self::CACHE_EXPIRATION_TIME);

                    return $this->buildDomainConfigs($salesChannelId);
                });
            } catch (\Throwable $e) {
                // If cache fails, fall back to direct processing
                return $this->buildDomainConfigs($salesChannelId);
            }
        }

        return $this->buildDomainConfigs($salesChannelId);
    }

    /**
     * Read the activated domains of a sales channel and reduce them to their widget configuration
     *
     * @param string $salesChannelId
     * @return array<string, array{widgetOrigin: string, domainId: string}>
     */
    private function buildDomainConfigs(string $salesChannelId): array
    {
        $configs = [];

        foreach ($this->queryDomainsByChannelId($salesChannelId) as $domain) {
            $customFields = $domain->getCustomFields();

            if (!$this->customFieldService->getBool($customFields, 'active', ReqserCustomFieldService::SALES_AGENT_PREFIX)) {
                continue;
            }

            $widgetOrigin = $this->customFieldService->getString($customFields, 'widgetOrigin', ReqserCustomFieldService::SALES_AGENT_PREFIX);

            $configs[$domain->getId()] = [
                'widgetOrigin' => rtrim($widgetOrigin ?: self::DEFAULT_WIDGET_ORIGIN, '/'),
                'domainId' => $domain->getId(),
            ];
        }

        return $configs;
    }

    /**
     * Query the domains of a sales channel that carry a ReqserSalesAgent custom field group
     *
     * @param string $salesChannelId
     * @return SalesChannelDomainCollection
     */
    private function queryDomainsByChannelId(string $salesChannelId): SalesChannelDomainCollection
    {
        $criteria = new Criteria();

        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('customFields.' . ReqserCustomFieldService::SALES_AGENT_PREFIX, null)
        ]));

        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));

        return $this->domainRepository->search($criteria, Context::createDefaultContext())->getEntities();
    }

    /**
     * Check if we're in a non-production environment (for testing purposes)
     * Disables caching in any environment that is not production
     */
    private function isNonProductionEnvironment(): bool
    {
        return $this->environment !== 'prod';
    }
}
