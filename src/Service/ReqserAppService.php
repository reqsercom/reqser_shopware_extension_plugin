<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ReqserAppService
{
    private Connection $connection;
    private RequestStack $requestStack;
    private CacheInterface $cache;
    private string $environment;

    public function __construct(
        Connection $connection,
        RequestStack $requestStack,
        CacheInterface $cache,
        string $environment
    ) {
        $this->connection = $connection;
        $this->requestStack = $requestStack;
        $this->cache = $cache;
        $this->environment = $environment;
    }

    public function isAppActive(): bool
    {
        try {
            // In development/testing environments, always return true (bypass app check)
            if ($this->environment !== 'prod') {
                return true;
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
            
            // Check if source is an IntegrationSource (API integration authentication)
            if (!($source instanceof \Shopware\Core\Framework\Api\Context\AdminApiSource)) {
                return false;
            }

            // Get integration ID from the source
            $integrationId = $source->getIntegrationId();
            if (!$integrationId) {
                return false;
            }

            // Query database to check if this integration belongs to the Reqser App
            $result = $this->connection->fetchOne(
                "SELECT app.name 
                 FROM integration 
                 LEFT JOIN app ON integration.app_id = app.id 
                 WHERE integration.id = :integration_id AND app.name = :app_name",
                [
                    'integration_id' => $integrationId,
                    'app_name' => 'ReqserApp'
                ]
            );

            return $result === 'ReqserApp';

        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the Reqser App ID from database
     * 
     * @return string|null
     */
    public function getReqserAppId(): ?string
    {
        try {
            return $this->connection->fetchOne(
                "SELECT id FROM `app` WHERE name = :app_name",
                ['app_name' => 'ReqserApp']
            );
        } catch (\Throwable $e) {
            return null;
        }
    }
}
