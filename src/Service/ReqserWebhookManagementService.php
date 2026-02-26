<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Reqser\Plugin\ReqserPlugin;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

class ReqserWebhookManagementService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $webhookRepository
    ) {
    }

    /**
     * Get the current status of all ReqserApp webhooks.
     *
     * @return list<array{webhookName: string, eventName: string, active: bool}>
     * @throws \InvalidArgumentException When the ReqserApp is not found
     */
    public function getAllWebhookStatuses(): array
    {
        $appId = $this->getAppId();

        $webhooks = $this->connection->fetchAllAssociative(
            'SELECT name, event_name, active FROM `webhook` WHERE app_id = :appId ORDER BY event_name ASC',
            ['appId' => $appId]
        );

        return array_map(static fn(array $row) => [
            'webhookName' => $row['name'],
            'eventName' => $row['event_name'],
            'active' => (bool) $row['active'],
        ], $webhooks);
    }

    /**
     * Get the current status of a single ReqserApp webhook by its Shopware event name.
     *
     * @param string $eventName The Shopware event name (e.g. "product.written")
     * @return array{webhookName: string, eventName: string, active: bool}
     *
     * @throws \InvalidArgumentException When the ReqserApp or the webhook cannot be found
     */
    public function getWebhookStatus(string $eventName): array
    {
        $webhook = $this->findWebhook($eventName);

        return [
            'webhookName' => $webhook['name'],
            'eventName' => $webhook['event_name'],
            'active' => (bool) $webhook['active'],
        ];
    }

    /**
     * Activate or deactivate a ReqserApp webhook by its Shopware event name.
     *
     * @param string  $eventName The Shopware event name (e.g. "product.written")
     * @param bool    $active    True to activate, false to deactivate
     * @param Context $context   Shopware context for the DAL write
     * @return array{webhookName: string, eventName: string, active: bool}
     *
     * @throws \InvalidArgumentException When the ReqserApp or the webhook cannot be found
     */
    public function setWebhookStatus(string $eventName, bool $active, Context $context): array
    {
        $webhook = $this->findWebhook($eventName);

        $webhookId = Uuid::fromBytesToHex($webhook['id']);

        $this->webhookRepository->update([
            [
                'id' => $webhookId,
                'active' => $active,
            ],
        ], $context);

        return [
            'webhookName' => $webhook['name'],
            'eventName' => $eventName,
            'active' => $active,
        ];
    }

    /**
     * @return string Binary app ID
     * @throws \InvalidArgumentException
     */
    private function getAppId(): string
    {
        $appId = $this->connection->fetchOne(
            'SELECT id FROM `app` WHERE name = :appName',
            ['appName' => ReqserPlugin::APP_NAME]
        );

        if ($appId === false) {
            throw new \InvalidArgumentException(ReqserPlugin::APP_NAME . ' not found in the app table');
        }

        return $appId;
    }

    /**
     * @return array{id: string, name: string, event_name: string, active: int}
     * @throws \InvalidArgumentException
     */
    private function findWebhook(string $eventName): array
    {
        $appId = $this->getAppId();

        $webhook = $this->connection->fetchAssociative(
            'SELECT id, name, event_name, active FROM `webhook` WHERE app_id = :appId AND event_name = :eventName',
            ['appId' => $appId, 'eventName' => $eventName]
        );

        if ($webhook === false) {
            throw new \InvalidArgumentException(
                sprintf('No webhook found for event "%s" on the %s', $eventName, ReqserPlugin::APP_NAME)
            );
        }

        return $webhook;
    }
}
