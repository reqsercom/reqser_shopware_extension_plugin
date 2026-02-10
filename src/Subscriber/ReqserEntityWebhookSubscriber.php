<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Webhook\Event\PreWebhooksDispatchEvent;
use Shopware\Core\Framework\Webhook\Webhook;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Filters Reqser webhook dispatches for product.written and category.written events.
 *
 * This subscriber acts as a gatekeeper on Shopware's native webhook dispatch pipeline.
 * It ensures that webhooks pointing to Reqser are only sent when:
 * - A logged-in admin user manually saves a product or category (not automated processes like PIM syncs)
 * - The rate limit has not been exceeded (max 1 per 10 seconds, max 100 per day per entity type)
 *
 * The subscriber hooks into two events:
 * 1. EntityWrittenContainerEvent — captures whether the write came from an admin user
 * 2. PreWebhooksDispatchEvent — filters the webhook list before Shopware sends them
 *
 * Shopware handles all HMAC signing, payload building, and queue dispatch — this subscriber only filters.
 */
class ReqserEntityWebhookSubscriber implements EventSubscriberInterface
{
    private const MAX_PER_DAY = 100;
    private const COOLDOWN_SECONDS = 10;
    private const WEBHOOK_PATH = '/app/shopware/webhook';
    private const FILTERED_EVENTS = ['product.written', 'category.written'];

    private bool $isAdminUserAction = false;

    private CacheItemPoolInterface $cache;
    private LoggerInterface $logger;

    public function __construct(
        CacheItemPoolInterface $cache,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityWrittenContainerEvent::class => 'onEntityWritten',
            PreWebhooksDispatchEvent::class => 'onPreWebhooksDispatch',
        ];
    }

    /**
     * Captures the context source of every entity write operation.
     *
     * Sets $isAdminUserAction to true only when a real admin user (with a userId)
     * triggered the write via the Admin API. Automated processes like PIM imports,
     * CLI commands, integrations, and scheduled tasks will set this to false.
     *
     * This method fires BEFORE onPreWebhooksDispatch because WebhookDispatcher
     * dispatches the entity event through the inner event dispatcher first,
     * then calls WebhookManager which triggers PreWebhooksDispatchEvent.
     */
    public function onEntityWritten(EntityWrittenContainerEvent $event): void
    {
        $source = $event->getContext()->getSource();

        $this->isAdminUserAction = $source instanceof AdminApiSource
            && $source->getUserId() !== null;
    }

    /**
     * Filters Reqser product/category webhooks before Shopware dispatches them.
     *
     * For each webhook in the dispatch queue:
     * - If it does not match the Reqser URL pattern, it passes through untouched.
     * - If it matches but the source is not an admin user, it is blocked.
     * - If it matches and the source is an admin user but the rate limit is exceeded, it is blocked.
     * - Otherwise it is allowed through and the rate limit counters are updated.
     *
     * Shopware continues to handle signing, payload, and delivery for all allowed webhooks.
     */
    public function onPreWebhooksDispatch(PreWebhooksDispatchEvent $event): void
    {
        try {
            $webhooksAllowed = [];

            foreach ($event->webhooks as $webhook) {
                if (!$this->shouldFilterWebhook($webhook)) {
                    $webhooksAllowed[] = $webhook;

                    continue;
                }

                if (!$this->isAdminUserAction) {
                    $this->logger->debug('[ReqserWebhookFilter] BLOCKED (not an admin user action)', [
                        'event' => $webhook->eventName,
                        'file' => __FILE__,
                        'line' => __LINE__,
                    ]);

                    continue;
                }

                $entityName = str_replace('.written', '', $webhook->eventName);
                $cooldownActive = $this->isCooldownActive($entityName);
                $dailyCount = $this->getDailyCount($entityName);

                if ($cooldownActive) {
                    $this->logger->debug('[ReqserWebhookFilter] BLOCKED (cooldown active, max 1 per ' . self::COOLDOWN_SECONDS . 's)', [
                        'event' => $webhook->eventName,
                        'dailyCount' => $dailyCount,
                        'file' => __FILE__,
                        'line' => __LINE__,
                    ]);

                    continue;
                }

                if ($dailyCount >= self::MAX_PER_DAY) {
                    $this->logger->debug('[ReqserWebhookFilter] BLOCKED (daily limit reached ' . $dailyCount . '/' . self::MAX_PER_DAY . ')', [
                        'event' => $webhook->eventName,
                        'dailyCount' => $dailyCount,
                        'file' => __FILE__,
                        'line' => __LINE__,
                    ]);

                    continue;
                }

                $this->updateRateLimitCounters($entityName);

                $this->logger->debug('[ReqserWebhookFilter] ALLOWED', [
                    'event' => $webhook->eventName,
                    'dailyCount' => $this->getDailyCount($entityName),
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]);

                $webhooksAllowed[] = $webhook;
            }

            $event->webhooks = array_values($webhooksAllowed);
        } catch (\Throwable $e) {
            $this->logger->error('[ReqserWebhookFilter] Error: ' . $e->getMessage(), [
                'file' => __FILE__,
                'line' => __LINE__,
            ]);
        } finally {
            $this->isAdminUserAction = false;
        }
    }

    /**
     * Determines whether a webhook should be subject to our rate limiting filter.
     *
     * Matches webhooks where:
     * - The host contains "reqser" (works for reqser.com, reqser.local.com, etc.)
     * - The path is /app/shopware/webhook
     * - The event is product.written or category.written
     *
     * All other webhooks (other apps, other events, other URLs) pass through unfiltered.
     */
    private function shouldFilterWebhook(Webhook $webhook): bool
    {
        if (!\in_array($webhook->eventName, self::FILTERED_EVENTS, true)) {
            return false;
        }

        $parsedUrl = parse_url($webhook->url);
        $host = $parsedUrl['host'] ?? '';
        $path = rtrim($parsedUrl['path'] ?? '', '/');

        return str_contains($host, 'reqser')
            && $path === self::WEBHOOK_PATH;
    }

    /**
     * Checks whether the cooldown period is still active for a given entity type.
     *
     * After a webhook is allowed through, a cooldown marker is cached for COOLDOWN_SECONDS.
     * During this period, subsequent webhooks for the same entity type are blocked.
     *
     * @return bool True if the cooldown is active and the webhook should be blocked.
     */
    private function isCooldownActive(string $entityName): bool
    {
        $cooldownKey = 'reqser_webhook_cooldown_' . $entityName;

        return $this->cache->hasItem($cooldownKey);
    }

    /**
     * Returns the number of webhooks allowed today for a given entity type.
     *
     * The counter resets at midnight (end of current day).
     */
    private function getDailyCount(string $entityName): int
    {
        $dailyKey = 'reqser_webhook_daily_' . $entityName . '_' . date('Ymd');
        $item = $this->cache->getItem($dailyKey);

        return $item->isHit() ? (int) $item->get() : 0;
    }

    /**
     * Updates the rate limit counters after allowing a webhook through.
     *
     * Sets a cooldown marker (blocks next webhook for COOLDOWN_SECONDS)
     * and increments the daily counter (blocks all webhooks after MAX_PER_DAY).
     */
    private function updateRateLimitCounters(string $entityName): void
    {
        $cooldownKey = 'reqser_webhook_cooldown_' . $entityName;
        $cooldownItem = $this->cache->getItem($cooldownKey);
        $cooldownItem->set(true);
        $cooldownItem->expiresAfter(self::COOLDOWN_SECONDS);
        $this->cache->save($cooldownItem);

        $dailyKey = 'reqser_webhook_daily_' . $entityName . '_' . date('Ymd');
        $dailyItem = $this->cache->getItem($dailyKey);
        $currentCount = $dailyItem->isHit() ? (int) $dailyItem->get() : 0;
        $endOfDay = (int) strtotime('tomorrow') - time();
        $dailyItem->set($currentCount + 1);
        $dailyItem->expiresAfter($endOfDay > 0 ? $endOfDay : 86400);
        $this->cache->save($dailyItem);
    }
}
