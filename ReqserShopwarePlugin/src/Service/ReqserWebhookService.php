<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\App\ShopId\ShopIdProvider;
use Psr\Log\LoggerInterface;

class ReqserWebhookService
{
    private string $webhookUrl;
    private ShopIdProvider $shopIdProvider;
    private LoggerInterface $logger;

    public function __construct(string $webhookUrl, ShopIdProvider $shopIdProvider, LoggerInterface $logger)
    {
        $this->webhookUrl = $webhookUrl;
        $this->shopIdProvider = $shopIdProvider;
        $this->logger = $logger;
    }

    public function sendErrorToWebhook(array $data): void
    {
        $url = $this->webhookUrl;
        // Add standard data (host and shop_id)
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
                // Optionally log curl error
                $error = curl_error($ch);
                $this->logger->error("Webhook error: " . $error, ['file' => __FILE__, 'line' => __LINE__]);
            }

            curl_close($ch);
        }
    }
}
