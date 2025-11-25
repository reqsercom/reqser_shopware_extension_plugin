<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ReqserAppService
{
    private Connection $connection;
    private RequestStack $requestStack;
    private CacheInterface $cache;
    private string $environment;
    private LoggerInterface $logger;

    public function __construct(
        Connection $connection,
        RequestStack $requestStack,
        CacheInterface $cache,
        string $environment,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->requestStack = $requestStack;
        $this->cache = $cache;
        $this->environment = $environment;
        $this->logger = $logger;
    }

    public function isAppActive(bool $skipCache = false): bool
    {
        try {
            // In development/testing environments, always return true (bypass app check)
            if ($this->environment !== 'prod') {
                return true;
            }
            
            // Skip cache if explicitly requested (e.g., for critical operations like snippet sync)
            if ($skipCache) {
                return $this->queryDatabaseForAppStatus();
            }
            
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

    /**
     * Verify that the request is authenticated via the Reqser App's integration
     * 
     * @param Context $context
     * @return bool True if request is from Reqser App integration, false otherwise
     */
    public function isRequestFromReqserApp(Context $context): bool
    {
        try {
            // In development/testing environments, bypass verification
            if ($this->environment !== 'prod') {
                return true;
            }

            // Get the source from the Context
            $source = $context->getSource();
            
            // Check if source is an AdminApiSource (API integration authentication)
            if (!($source instanceof AdminApiSource)) {
                return false;
            }

            // Get integration ID from the source
            $integrationId = $source->getIntegrationId();
            
            if (!$integrationId) {
                return false;
            }

            // Convert integration ID to binary for database query
            $integrationIdBinary = hex2bin($integrationId);

            // Query database to check if this integration has the label 'ReqserApp'
            $result = $this->connection->fetchOne(
                "SELECT label FROM integration WHERE id = :integration_id AND label = :label",
                [
                    'integration_id' => $integrationIdBinary,
                    'label' => 'ReqserApp'
                ]
            );

            return $result === 'ReqserApp';

        } catch (\Throwable $e) {
            return false;
        }
    }
}
