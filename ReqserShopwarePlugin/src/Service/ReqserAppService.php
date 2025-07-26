<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;

class ReqserAppService
{
    private Connection $connection;
    private CacheInterface $cache;

    public function __construct(
        Connection $connection,
        CacheInterface $cache
    ) {
        $this->connection = $connection;
        $this->cache = $cache;
    }

    public function isAppActive(): bool
    {
        if (!$this->cache->hasItem('reqser_app_active')) {
            // Double check if the app is active
            $app_name = "ReqserApp";
            $is_app_active = $this->connection->fetchOne(
                "SELECT active FROM `app` WHERE name = :app_name",
                ['app_name' => $app_name]
            );
            
            if (!$is_app_active) {
                return false;
            }
            
            $cacheItem = $this->cache->getItem('reqser_app_active');
            $cacheItem->set(true);
            $cacheItem->expiresAfter(86400);
            $this->cache->save($cacheItem);
        }
        
        return true;
    }
} 