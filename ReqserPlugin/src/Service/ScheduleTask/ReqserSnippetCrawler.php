<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduleTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ReqserSnippetCrawler extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'reqser_plugin.reqser_snippet_crawler';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 24 hours in seconds
    }
}
