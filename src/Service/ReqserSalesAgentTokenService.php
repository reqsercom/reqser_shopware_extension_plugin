<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Reqser\Plugin\ReqserPlugin;

/**
 * Mints the short-lived token that lets Reqser trust a storefront visitor belongs to this shop.
 */
class ReqserSalesAgentTokenService
{
    public const TOKEN_LIFETIME_SECONDS = 300;

    private const SHOP_ID_CONFIG_KEY = 'core.app.shopIdV2';

    private Connection $connection;
    private LoggerInterface $logger;

    /**
     * @param Connection $connection
     * @param LoggerInterface $logger
     */
    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * Build a signed token describing the visitor's storefront context
     *
     * Returns null when the shop is not registered with Reqser, so the caller can stay inert
     * instead of handing out an unverifiable token.
     *
     * @param array $claims
     * @return ?array{token: string, expiresAt: int}
     */
    public function issue(array $claims): ?array
    {
        $secret = $this->appSecret();
        $shopId = $this->shopId();

        if ($secret === null || $shopId === null) {
            return null;
        }

        $issued_at = time();
        $expires_at = $issued_at + self::TOKEN_LIFETIME_SECONDS;

        $payload = array_merge($claims, [
            'shopId' => $shopId,
            'iat' => $issued_at,
            'exp' => $expires_at,
        ]);

        $encoded = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $secret, true));

        return [
            'token' => 'v1.' . $encoded . '.' . $signature,
            'expiresAt' => $expires_at,
        ];
    }

    /**
     * Read the per-shop secret the Reqser App was registered with
     *
     * @return ?string
     */
    private function appSecret(): ?string
    {
        try {
            $secret = $this->connection->fetchOne(
                "SELECT app_secret FROM `app` WHERE name = :app_name",
                ['app_name' => ReqserPlugin::APP_NAME]
            );

            return is_string($secret) && $secret !== '' ? $secret : null;
        } catch (\Throwable $e) {
            $this->logger->error('Reqser sales agent: could not read the app secret.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Read the shop id Shopware generated for the app registration
     *
     * @return ?string
     */
    private function shopId(): ?string
    {
        try {
            $raw = $this->connection->fetchOne(
                "SELECT configuration_value FROM system_config WHERE configuration_key = :key",
                ['key' => self::SHOP_ID_CONFIG_KEY]
            );

            if (!is_string($raw) || $raw === '') {
                return null;
            }

            $decoded = json_decode($raw, true);
            $shopId = $decoded['_value']['id'] ?? null;

            return is_string($shopId) && $shopId !== '' ? $shopId : null;
        } catch (\Throwable $e) {
            $this->logger->error('Reqser sales agent: could not read the app shop id.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param string $value
     * @return string
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
