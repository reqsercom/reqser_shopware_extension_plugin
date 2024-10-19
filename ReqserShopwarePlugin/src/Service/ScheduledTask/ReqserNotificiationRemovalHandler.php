<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\App\ShopId\ShopIdProvider;
use Reqser\Plugin\Service\ReqserAppStatusService;
use Reqser\Plugin\Service\ReqserWebhookService;
use Reqser\Plugin\Service\ReqserNotificationService;

class ReqserNotificiationRemovalHandler extends ScheduledTaskHandler
{
    private Connection $connection;
    private LoggerInterface $logger;
    private ShopIdProvider $shopIdProvider;
    private ReqserAppStatusService $appStatusService;
    private ReqserWebhookService $webhookService;
    private ReqserNotificationService $notificationService;
    

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        Connection $connection,
        LoggerInterface $logger,
        ShopIdProvider $shopIdProvider,
        ReqserAppStatusService $appStatusService,
        ReqserWebhookService $webhookService,
        ReqserNotificationService $notificationService,
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->shopIdProvider = $shopIdProvider;
        $this->appStatusService = $appStatusService;
        $this->webhookService = $webhookService;
        $this->notificationService = $notificationService;
    }

    public function run(): void
    {
        $this->notificationService->sendAdminNotification('ReqserNotificiationRemovalHandler run');
        try {
            $this->removeReqserNotifications();
        } catch (\Throwable $e) {
            // Use ReqserWebhookService to send error to webhook
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
        $integration_id = $this->connection->fetchOne(
            "SELECT id FROM `integration` WHERE label = :label",
            ['label' => $app_name]
        );

        if ($integration_id) {
            $sql = "
                DELETE FROM `notification`
                WHERE `created_by_integration_id` = :integration_id
                AND `created_at` < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ";
            $this->connection->executeStatement($sql, ['integration_id' => $integration_id]);
        }
    }

    public static function getHandledMessages(): iterable
    {
        return [ReqserNotificiationRemoval::class];
    }
}
