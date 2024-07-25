<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\MessageQueue\Message\Message;

#[AsMessageHandler(handles: ReqserSnippetCrawler::class)]
class ReqserSnippetCrawlerHandler extends ScheduledTaskHandler
{
    public function run(): void
    {
        // ...
    }

    public function handle(ReqserSnippetCrawler $task): void
    {
        // Your task execution logic here
    }

    public static function getHandledMessages(): iterable
    {
        return [ReqserSnippetCrawler::class];
    }
}
