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

    public function sendErrorToWebhook(array $data, bool $echoData = false): void
    {
        
        $url = $this->webhookUrl;
        // Add standard data (host and shop_id)
        $data['host'] = $_SERVER['HTTP_HOST'] ?? 'unknown';
        $data['shopId'] = $this->shopIdProvider->getShopId() ?? 'unknown';

        $payload = json_encode($data);

        // Echo data if requested
        if ($echoData) {
            $this->echoWebhookData($url, $data, $payload);
            return;
        }

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

    /**
     * Echo webhook data for debug purposes
     * Only works when debug mode is enabled
     * 
     * @param string $url The webhook URL
     * @param array $data The webhook data
     * @param string $payload The JSON payload
     */
    private function echoWebhookData(string $url, array $data, string $payload): void
    {
        // Only echo if this is a debug webhook (indicating debug mode is active)
        if (($data['type'] ?? '') !== 'debug') {
            return;
        }

        echo "<div style='background: #e3f2fd; padding: 15px; margin: 10px; border: 1px solid #90caf9; font-family: monospace; border-radius: 5px;'>";
        echo "<h3>📡 WEBHOOK DEBUG ECHO</h3>";
        echo "<strong>URL:</strong> " . htmlspecialchars($url) . "<br>";
        echo "<strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "<br>";
        echo "<strong>Data Type:</strong> " . htmlspecialchars($data['type'] ?? 'unknown') . "<br>";
        echo "<strong>Info:</strong> " . htmlspecialchars($data['info'] ?? 'no info') . "<br>";
        echo "<strong>Raw Payload:</strong><br>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto;'>" . htmlspecialchars($payload) . "</pre>";
        echo "</div>";
    }
}
