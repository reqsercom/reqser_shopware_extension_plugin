<?php declare(strict_types=1);

namespace Reqser\SnippetCrawler\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Reqser\SnippetCrawler\Service\SnippetCrawlerService;

class SnippetCrawlerTaskHandler extends ScheduledTaskHandler
{
    private SnippetCrawlerService $snippetCrawlerService;

    public function __construct(SnippetCrawlerService $snippetCrawlerService)
    {
        $this->snippetCrawlerService = $snippetCrawlerService;
    }

    public function run(): void
    {
        $this->snippetCrawlerService->crawlAndUpdateSnippets();
    }
}
