<?php declare(strict_types=1);

namespace ReqserPlugin\Service\ScheduleTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\Handler\AbstractMessageHandler;
use Shopware\Core\Framework\MessageQueue\Message\Message;

class ReqserSnippetCrawlerHandler extends AbstractMessageHandler
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public static function getHandledMessages(): iterable
    {
        return [
            ReqserSnippetCrawler::class,
        ];
    }

    public function handle(Message $message): void
    {
        // Your task logic here
        $this->logger->info('ReqserSnippetCrawler is being executed.');
    }
}
