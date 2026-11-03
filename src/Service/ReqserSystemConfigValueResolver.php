<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Resolves a system-config key to every distinct string value it holds across the
 * global scope and all sales channels.
 */
class ReqserSystemConfigValueResolver
{
    private Connection $connection;
    private SystemConfigService $systemConfigService;

    /** @var array<string, list<string>> */
    private array $cache = [];

    /** @var list<string>|null */
    private ?array $salesChannelIds = null;

    /**
     * @param Connection $connection
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(Connection $connection, SystemConfigService $systemConfigService)
    {
        $this->connection = $connection;
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * Returns every distinct non-empty string value the key holds.
     *
     * @param string $configKey
     * @return list<string>
     */
    public function resolve(string $configKey): array
    {
        if (isset($this->cache[$configKey])) {
            return $this->cache[$configKey];
        }

        $values = [];

        foreach ([null, ...$this->getSalesChannelIds()] as $scope) {
            try {
                $value = $this->systemConfigService->get($configKey, $scope);
            } catch (\Throwable $e) {
                continue;
            }

            if (is_string($value) && $value !== '') {
                $values[$value] = true;
            }
        }

        return $this->cache[$configKey] = array_keys($values);
    }

    /**
     * @return list<string>
     */
    private function getSalesChannelIds(): array
    {
        if ($this->salesChannelIds === null) {
            try {
                $this->salesChannelIds = $this->connection->fetchFirstColumn(
                    'SELECT LOWER(HEX(`id`)) FROM `sales_channel`'
                );
            } catch (\Throwable $e) {
                $this->salesChannelIds = [];
            }
        }

        return $this->salesChannelIds;
    }
}
