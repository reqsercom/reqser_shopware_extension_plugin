<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\App\ShopId\ShopIdProvider;
use Shopware\Core\Framework\Context;

class ReqserNotificiationRemoval extends ScheduledTaskHandler
{
    private Connection $connection;
    private LoggerInterface $logger;
    private ContainerInterface $container;
    private ShopIdProvider $shopIdProvider;
    private $webhookUrl = 'https://reqser.com/app/shopware/webhook/plugin';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        Connection $connection,
        LoggerInterface $logger,
        ContainerInterface $container,
        ShopIdProvider $shopIdProvider
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->container = $container;
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
        $sql = "SELECT id, iso FROM snippet_set";
        $result = $this->connection->fetchAllAssociative($sql);

        foreach ($result as $row) {
            
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

    
}
