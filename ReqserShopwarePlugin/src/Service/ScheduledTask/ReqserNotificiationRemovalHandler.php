<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\App\ShopId\ShopIdProvider;

class ReqserNotificiationRemovalHandler extends ScheduledTaskHandler
{
    private Connection $connection;
    private LoggerInterface $logger;
    private ShopIdProvider $shopIdProvider;
    private $webhookUrl = 'https://reqser.com/app/shopware/webhook/plugin';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        Connection $connection,
        LoggerInterface $logger,
        ShopIdProvider $shopIdProvider
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->shopIdProvider = $shopIdProvider;
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
    
            // Send error to webhook
            $this->sendErrorToWebhook([
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
        }
    }

    private function sendErrorToWebhook(array $data): void
    {
        $url = $this->webhookUrl;
        //Add Standard Data host and shop_id
        $data['host'] = $_SERVER['HTTP_HOST'] ?? 'unknown';
        $data['shopId'] = $this->shopIdProvider->getShopId() ?? 'unknown';

        $payload = json_encode($data);

        if (
            function_exists('curl_init') &&
            function_exists('curl_setopt') &&
            function_exists('curl_exec') &&
            function_exists('curl_close')
        ) {
            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $result = curl_exec($ch);

            if ($result === false) {
                // Optionally handle errors here
                $error = curl_error($ch);
                // You can log this error if necessary
            }

            curl_close($ch);
        } 
    }

    public static function getHandledMessages(): iterable
    {
        return [ReqserNotificiationRemoval::class];
    }
}
