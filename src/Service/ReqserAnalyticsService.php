<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

class ReqserAnalyticsService
{
    private Connection $connection;
    private LoggerInterface $logger;

    public function __construct(
        Connection $connection,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * Get order distribution percentages grouped by language.
     * All filter parameters are optional.
     * Amounts are normalized to system currency via currency_factor.
     * Returns percentages rounded to 1 decimal place.
     *
     * @param array{from?: string, until?: string, salesChannelId?: string} $filters
     * @return array<int, array{language_id: string, percentage_amount_orders: float, percentage_amount_total: float}>
     */
    public function getLanguageDistribution(array $filters = []): array
    {
        $conditions = ['o.version_id = :versionId'];
        $params = [
            'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ];

        $joins = 'LEFT JOIN `currency` c ON o.currency_factor IS NULL AND c.id = o.currency_id';

        if (!empty($filters['from'])) {
            $conditions[] = 'o.order_date_time >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }

        if (!empty($filters['until'])) {
            $conditions[] = 'o.order_date_time <= :until';
            $params['until'] = $filters['until'] . ' 23:59:59';
        }

        if (!empty($filters['salesChannelId'])) {
            $conditions[] = 'o.sales_channel_id = :salesChannelId';
            $params['salesChannelId'] = Uuid::fromHexToBytes($filters['salesChannelId']);
        }

        $where = implode(' AND ', $conditions);

        $sql = "
            SELECT
                LOWER(HEX(o.language_id)) AS language_id,
                COUNT(o.id) AS order_count,
                ROUND(SUM(
                    o.amount_total / IFNULL(o.currency_factor, c.factor)
                ), 2) AS amount_share
            FROM `order` o
            {$joins}
            WHERE {$where}
            GROUP BY o.language_id
            ORDER BY amount_share DESC
        ";

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $totalOrders = 0;
        $totalAmount = 0.0;

        foreach ($rows as $row) {
            $totalOrders += (int) $row['order_count'];
            $totalAmount += (float) $row['amount_share'];
        }

        $languages = [];

        foreach ($rows as $row) {
            $orderCount = (int) $row['order_count'];
            $amountShare = (float) $row['amount_share'];

            $languages[] = [
                'language_id' => $row['language_id'],
                'percentage_amount_orders' => $totalOrders > 0
                    ? round(($orderCount / $totalOrders) * 100, 1)
                    : 0.0,
                'percentage_amount_total' => $totalAmount > 0
                    ? round(($amountShare / $totalAmount) * 100, 1)
                    : 0.0,
            ];
        }

        return $languages;
    }
}
