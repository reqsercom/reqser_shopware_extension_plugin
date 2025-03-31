<?php 
declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Reqser\Plugin\Service\ReqserNotificationService;
use Reqser\Plugin\Service\ReqserWebhookService;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler(handles: ReqserNotificiationRemoval::class)]
class ReqserNotificiationRemovalHandler extends ScheduledTaskHandler
{
    private Connection $connection;
    private LoggerInterface $logger;
    private $notificationService;
    private $webhookService;
    private CacheInterface $cache;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        Connection $connection,
        LoggerInterface $logger,
        ReqserNotificationService $notificationService,
        ReqserWebhookService $webhookService,
        CacheInterface $cache
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
        $this->webhookService = $webhookService;
        $this->cache = $cache;
    }

    public function run(): void
    { 
        // Preload snippet set IDs
        try {
            $this->removeReqserNotifications();
        } catch (\Throwable $e) {
            // Log the error message and continue with the next directory
            if (method_exists($this->logger, 'error')) {
                $this->logger->error('Reqser Plugin Error remove Notifictaions', [
                    'message' => $e->getMessage(),
                ]);
            }
            $this->webhookService->sendErrorToWebhook([
                'type' => 'error',
                'function' => 'removeReqserNotifications',
                'message' => $e->getMessage() ?? 'unknown',
                'trace' => $e->getTraceAsString() ?? 'unknown',
                'timestamp' => date('Y-m-d H:i:s'),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
        }
        
    }

    private function removeReqserNotifications(): void
    {
        $app_name = "ReqserApp";
        
        // Fetch the integration ID associated with your app
        $integration_id = $this->connection->fetchOne(
            "SELECT id FROM `integration` WHERE label = :label",
            ['label' => $app_name]
        );
    
        if ($integration_id) {
             // Delete notifications older than 1 hour associated with your integration ID
                $sql = "
                DELETE FROM `notification`
                WHERE `created_by_integration_id` = :integration_id
                AND `created_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ";

            $deletedRows = $this->connection->executeStatement($sql, ['integration_id' => $integration_id]);

            //Check if App is active and if so add to cache
            $is_app_active = $this->connection->fetchOne(
                "SELECT active FROM `app` WHERE integration_id = :integration_id",
                ['integration_id' => $integration_id]
            );
            if ($is_app_active) {
                $cacheItem = $this->cache->getItem('reqser_app_active');
                $cacheItem->set(true);
                $cacheItem->expiresAfter(86400);
                $this->cache->save($cacheItem);
            }
        }
    }
}
