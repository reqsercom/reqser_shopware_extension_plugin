<?php declare(strict_types=1);

namespace Reqser\SnippetCrawler\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class SnippetCrawlerTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'reqser.snippet_crawler_task';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 24 hours in seconds
    }
}
