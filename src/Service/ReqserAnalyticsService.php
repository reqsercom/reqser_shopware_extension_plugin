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
     * Get order and revenue percentages grouped by language for a given date range.
     * Amounts are normalized to system currency via currency_factor.
     * Returns percentages rounded to 1 decimal place.
     *
     * @param string $from Start date (Y-m-d)
     * @param string $until End date (Y-m-d)
     * @return array<int, array{language_id: string, percentage_amount_orders: float, percentage_amount_revenue: float}>
     */
    public function getRevenueByLanguage(string $from, string $until): array
    {
        $sql = <<<'SQL'
            SELECT
                LOWER(HEX(o.language_id)) AS language_id,
                COUNT(o.id) AS order_count,
                ROUND(SUM(
                    o.amount_total / IFNULL(o.currency_factor, c.factor)
                ), 2) AS revenue
            FROM `order` o
            LEFT JOIN `currency` c
                ON o.currency_factor IS NULL AND c.id = o.currency_id
            WHERE o.order_date_time >= :from
              AND o.order_date_time <= :until
              AND o.version_id = :versionId
            GROUP BY o.language_id
            ORDER BY revenue DESC
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'from' => $from . ' 00:00:00',
            'until' => $until . ' 23:59:59',
            'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
        ]);

        $totalOrders = 0;
        $totalRevenue = 0.0;

        foreach ($rows as $row) {
            $totalOrders += (int) $row['order_count'];
            $totalRevenue += (float) $row['revenue'];
        }

        $languages = [];

        foreach ($rows as $row) {
            $orderCount = (int) $row['order_count'];
            $revenue = (float) $row['revenue'];

            $languages[] = [
                'language_id' => $row['language_id'],
                'percentage_amount_orders' => $totalOrders > 0
                    ? round(($orderCount / $totalOrders) * 100, 1)
                    : 0.0,
                'percentage_amount_revenue' => $totalRevenue > 0
                    ? round(($revenue / $totalRevenue) * 100, 1)
                    : 0.0,
            ];
        }

        return $languages;
    }
}
