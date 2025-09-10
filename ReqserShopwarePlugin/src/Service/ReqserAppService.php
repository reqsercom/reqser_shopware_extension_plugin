<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ReqserAppService
{
    private Connection $connection;
    private RequestStack $requestStack;
    private CacheInterface $cache;

    public function __construct(
        Connection $connection,
        RequestStack $requestStack,
        CacheInterface $cache
    ) {
        $this->connection = $connection;
        $this->requestStack = $requestStack;
        $this->cache = $cache;
    }

    public function isAppActive(): bool
    {
        try {
            // Use server-side cache for all users (much more efficient)
            return $this->cache->get('reqser_app_active', function (ItemInterface $item) {
                // Cache for 1 hour (3600 seconds) - matches Shopware default TTL
                $item->expiresAfter(3600);
                
                // Query database only when cache expires
                return $this->queryDatabaseForAppStatus();
            });
            
        } catch (\Throwable $e) {
            // If cache fails, fall back to direct database query
            return $this->queryDatabaseForAppStatus();
        }
    }
    
    private function queryDatabaseForAppStatus(): bool
    {
        try {
            $app_name = "ReqserApp";
            $is_app_active = $this->connection->fetchOne(
                "SELECT active FROM `app` WHERE name = :app_name",
                ['app_name' => $app_name]
            );
            
            return (bool)$is_app_active;
        } catch (\Throwable $e) {
            return false;
        }
    }
} 