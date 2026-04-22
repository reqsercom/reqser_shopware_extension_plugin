<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Reqser\Plugin\ReqserPlugin;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class ReqserWebhookManagementService
{
    private Connection $connection;
    private EntityRepository $webhookRepository;

    public function __construct(
        Connection $connection,
        EntityRepository $webhookRepository
    ) {
        $this->connection = $connection;
        $this->webhookRepository = $webhookRepository;
    }

    /**
     * Get the current status of all ReqserApp webhooks.
     *
     * @return list<array{webhookName: string, eventName: string, active: bool}>
     * @throws \InvalidArgumentException When the ReqserApp is not found
     */
    public function getAllWebhookStatuses(): array
    {
        $appId = $this->getAppIdHex();

        $webhooks = $this->connection->fetchAllAssociative(
            'SELECT name, event_name, active
             FROM `webhook`
             WHERE app_id = UNHEX(:appId)
             ORDER BY event_name ASC',
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
     * Persists the change and then re-reads the row from the database, so the
     * returned `active` value reflects the actual persisted state — not the
     * value that was requested. This surfaces any silent write failures
     * (wrong scope, missing permissions, zero-affected-rows) to the caller
     * instead of masking them behind an optimistic "success" response.
     *
     * @param string  $eventName The Shopware event name (e.g. "product.written")
     * @param bool    $active    True to activate, false to deactivate
     * @return array{webhookName: string, eventName: string, active: bool}
     *
     * @throws \InvalidArgumentException When the ReqserApp or the webhook cannot be found
     * @throws \RuntimeException         When the persisted state does not match the requested state
     */
    public function setWebhookStatus(string $eventName, bool $active): array
    {
        $webhook = $this->findWebhook($eventName);
        $webhookId = $webhook['id'];

        $context = Context::createDefaultContext();

        // Run the write in SYSTEM_SCOPE so WriteProtected(SYSTEM_SCOPE) fields
        // (e.g. webhook.error_count and any future hardening on webhook rows)
        // do not silently become no-ops. The ReqserApp is the only caller and
        // this operation is strictly back-office, so system scope is
        // appropriate.
        $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($webhookId, $active): void {
            $this->webhookRepository->update([
                [
                    'id' => $webhookId,
                    'active' => $active,
                ],
            ], $systemContext);
        });

        // Re-read from DB so the response reflects the persisted truth rather
        // than the requested value. If the write silently failed for any
        // reason, the caller will see the actual DB value rather than an
        // optimistic success echo.
        $persisted = $this->findWebhook($eventName);
        $persistedActive = (bool) $persisted['active'];

        if ($persistedActive !== $active) {
            throw new \RuntimeException(sprintf(
                'Failed to persist webhook.active=%s for event "%s" on the %s: '
                . 'database still reports active=%s after update.',
                $active ? 'true' : 'false',
                $eventName,
                ReqserPlugin::APP_NAME,
                $persistedActive ? 'true' : 'false'
            ));
        }

        return [
            'webhookName' => $persisted['name'],
            'eventName' => $persisted['event_name'],
            'active' => $persistedActive,
        ];
    }

    /**
     * Fetch the ReqserApp app_id as a lower-case 32-char hex string.
     *
     * Shopware stores `app.id` as BINARY(16). Fetching via `LOWER(HEX(id))` in
     * SQL — instead of receiving the raw 16-byte binary and converting in PHP —
     * avoids any driver/charset edge cases that can silently corrupt binary
     * payloads on their way into prepared-statement parameters.
     *
     * @return string 32-char lower-case hex
     * @throws \InvalidArgumentException
     */
    private function getAppIdHex(): string
    {
        $appId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM `app` WHERE name = :appName',
            ['appName' => ReqserPlugin::APP_NAME]
        );

        if ($appId === false || $appId === null || $appId === '') {
            throw new \InvalidArgumentException(ReqserPlugin::APP_NAME . ' not found in the app table');
        }

        return (string) $appId;
    }

    /**
     * @return array{id: string, name: string, event_name: string, active: int}
     * @throws \InvalidArgumentException
     */
    private function findWebhook(string $eventName): array
    {
        $appId = $this->getAppIdHex();

        $webhook = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, name, event_name, active
             FROM `webhook`
             WHERE app_id = UNHEX(:appId) AND event_name = :eventName',
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
