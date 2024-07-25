<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

#[AsMessageHandler(handles: ExampleTask::class)]
class ExampleTaskHandler extends ScheduledTaskHandler
{
    public function run(): void
    {
        // ...
    }

    public function handle(ExampleTask $task): void
    {
        // Your task execution logic here
    }

    public static function getHandledMessages(): iterable
    {
        return [ExampleTask::class];
    }
}