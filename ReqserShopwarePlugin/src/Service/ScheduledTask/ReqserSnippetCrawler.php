<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ReqserSnippetCrawler extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'reqser.snippet_crawler';
    }

    public static function getDefaultInterval(): int
    {
        return 120; // 24 hours in seconds
    }
}
