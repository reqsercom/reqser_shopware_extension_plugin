<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Webhook\Event\PreWebhooksDispatchEvent;
use Shopware\Core\Framework\Webhook\Webhook;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ReqserEntityWebhookSubscriber implements EventSubscriberInterface
{
    private const MAX_PER_DAY = 100;
    private const COOLDOWN_SECONDS = 10;
    private const WEBHOOK_URL = 'https://www.reqser.com/app/shopware/webhook';
    private const FILTERED_EVENTS = ['product.written', 'category.written'];

    private bool $isAdminUserAction = false;

    private CacheInterface $cache;
    private LoggerInterface $logger;

    public function __construct(
        CacheInterface $cache,
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

    public function onEntityWritten(EntityWrittenContainerEvent $event): void
    {
        $source = $event->getContext()->getSource();

        $this->isAdminUserAction = $source instanceof AdminApiSource
            && $source->getUserId() !== null;
    }

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

                if ($this->isCooldownActive($entityName) || $this->getDailyCount($entityName) >= self::MAX_PER_DAY) {
                    $this->logger->debug('[ReqserWebhookFilter] BLOCKED (rate limited)', [
                        'event' => $webhook->eventName,
                        'dailyCount' => $this->getDailyCount($entityName),
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
        }
    }

    private function shouldFilterWebhook(Webhook $webhook): bool
    {
        return $webhook->url === self::WEBHOOK_URL
            && \in_array($webhook->eventName, self::FILTERED_EVENTS, true);
    }

    private function isCooldownActive(string $entityName): bool
    {
        $cooldownKey = 'reqser_webhook_cooldown_' . $entityName;

        return $this->cache->get($cooldownKey, function (ItemInterface $item) {
            $item->expiresAfter(1);

            return false;
        }) === true;
    }

    private function getDailyCount(string $entityName): int
    {
        $dailyKey = 'reqser_webhook_daily_' . $entityName . '_' . date('Ymd');

        return (int) $this->cache->get($dailyKey, function (ItemInterface $item) {
            $endOfDay = (int) strtotime('tomorrow') - time();
            $item->expiresAfter($endOfDay > 0 ? $endOfDay : 86400);

            return 0;
        });
    }

    private function updateRateLimitCounters(string $entityName): void
    {
        $cooldownKey = 'reqser_webhook_cooldown_' . $entityName;
        $this->cache->delete($cooldownKey);
        $this->cache->get($cooldownKey, function (ItemInterface $item) {
            $item->expiresAfter(self::COOLDOWN_SECONDS);

            return true;
        });

        $dailyKey = 'reqser_webhook_daily_' . $entityName . '_' . date('Ymd');
        $currentCount = $this->getDailyCount($entityName);
        $this->cache->delete($dailyKey);
        $this->cache->get($dailyKey, function (ItemInterface $item) use ($currentCount) {
            $endOfDay = (int) strtotime('tomorrow') - time();
            $item->expiresAfter($endOfDay > 0 ? $endOfDay : 86400);

            return $currentCount + 1;
        });
    }
}
