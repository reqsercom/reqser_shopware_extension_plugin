<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ReqserNotificiationRemoval extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'reqser.notification_removal';
    }

    public static function getDefaultInterval(): int
    {
        return 3600; // 1 hour in seconds
    }
}
