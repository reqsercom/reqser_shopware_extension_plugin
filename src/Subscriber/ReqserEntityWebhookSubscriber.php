<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Webhook\Event\PreWebhooksDispatchEvent;
use Shopware\Core\Framework\Webhook\Webhook;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ReqserEntityWebhookSubscriber implements EventSubscriberInterface
{
    private const MAX_PER_DAY = 100;
    private const COOLDOWN_SECONDS = 60;
    private const WEBHOOK_URL = 'https://www.reqser.com/app/shopware/webhook';
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
     * Captures whether the current write operation comes from an admin user (manual save).
     * This fires BEFORE PreWebhooksDispatchEvent because WebhookDispatcher dispatches
     * the entity event through the inner dispatcher first, then calls WebhookManager.
     */
    public function onEntityWritten(EntityWrittenContainerEvent $event): void
    {
        $source = $event->getContext()->getSource();

        $this->isAdminUserAction = $source instanceof AdminApiSource
            && $source->getUserId() !== null;
    }

    /**
     * Filters ReqserApp product/category webhooks before Shopware dispatches them.
     * Only allows webhooks through when triggered by a manual admin user save
     * and the rate limit has not been exceeded.
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

                // Block if not a manual admin user save
                if (!$this->isAdminUserAction) {
                    continue;
                }

                // Block if rate limited
                $entityName = str_replace('.written', '', $webhook->eventName);
                if ($this->isRateLimited($entityName)) {
                    continue;
                }

                // Allow webhook through and update rate limit counters
                $this->updateRateLimitCounters($entityName);
                $webhooksAllowed[] = $webhook;
            }

            $event->webhooks = array_values($webhooksAllowed);
        } catch (\Throwable $e) {
            $this->logger->error('ReqserEntityWebhookSubscriber error: ' . $e->getMessage(), [
                'file' => __FILE__,
                'line' => __LINE__,
            ]);
        }
    }

    private function shouldFilterWebhook(Webhook $webhook): bool
    {
        return $webhook->url === self::WEBHOOK_URL
            && \in_array($webhook->eventName, self::FILTERED_EVENTS, true);
    }

    private function isRateLimited(string $entityName): bool
    {
        // Check cooldown — max 1 webhook per minute per entity type
        $cooldownItem = $this->cache->getItem('reqser_webhook_cooldown_' . $entityName);
        if ($cooldownItem->isHit()) {
            return true;
        }

        // Check daily limit — max 100 per day per entity type
        $dailyItem = $this->cache->getItem('reqser_webhook_daily_' . $entityName . '_' . date('Ymd'));
        if ($dailyItem->isHit() && (int) $dailyItem->get() >= self::MAX_PER_DAY) {
            return true;
        }

        return false;
    }

    private function updateRateLimitCounters(string $entityName): void
    {
        // Set cooldown marker — expires after COOLDOWN_SECONDS
        $cooldownItem = $this->cache->getItem('reqser_webhook_cooldown_' . $entityName);
        $cooldownItem->set(true);
        $cooldownItem->expiresAfter(self::COOLDOWN_SECONDS);
        $this->cache->save($cooldownItem);

        // Increment daily counter — expires at end of current day
        $dailyKey = 'reqser_webhook_daily_' . $entityName . '_' . date('Ymd');
        $dailyItem = $this->cache->getItem($dailyKey);
        $currentCount = $dailyItem->isHit() ? (int) $dailyItem->get() : 0;
        $dailyItem->set($currentCount + 1);
        $endOfDay = (int) strtotime('tomorrow') - time();
        $dailyItem->expiresAfter($endOfDay > 0 ? $endOfDay : 86400);
        $this->cache->save($dailyItem);
    }
}
