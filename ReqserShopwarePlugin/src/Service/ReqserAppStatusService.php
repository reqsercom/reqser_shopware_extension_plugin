<?php
declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;

class ReqserAppStatusService
{
    private $appRepository;
    private $cache;

    public function __construct(EntityRepository $appRepository, CacheInterface $cache)
    {
        $this->appRepository = $appRepository;
        $this->cache = $cache;
    }

    public function isAppActive(): bool
    {
        // Cache key for app status
        $cacheKey = 'reqser_app_active';

        // Check cache first, if it's already cached, return the value
        return $this->cache->get($cacheKey, function() {
            // Check if the ReqserApp is active from the database
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', 'ReqserApp'));
            $criteria->addFilter(new EqualsFilter('active', true));

            $result = $this->appRepository->search($criteria, Context::createDefaultContext());

            // Cache the result and return it (expires after 1 hour)
            return $result->getTotal() > 0;
        });
    }

    // Optionally, add a method to force-refresh the cache
    public function refreshAppStatus(): bool
    {
        $this->cache->delete('reqser_app_active'); // Clear cache
        return $this->isAppActive(); // Recheck and cache again
    }
}
