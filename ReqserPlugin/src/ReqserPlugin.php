<?php declare(strict_types=1);

namespace Reqser\Plugin;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskDefinition;
use Shopware\Core\Framework\Uuid\Uuid;
use Reqser\Plugin\Service\ScheduledTask\ExampleTask;
use Reqser\Plugin\Service\ScheduledTask\ExampleTaskHandler;

class ReqserPlugin extends Plugin
{


    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->scheduleTask();
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->scheduleTask();
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->runTask();
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);
        $this->removeTask();
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        $this->removeTask();
    }

    private function scheduleTask(): void
    {
        $connection = $this->container->get(Connection::class);
        $currentTime = (new \DateTime())->format('Y-m-d H:i:s');

        $connection->executeStatement(
            'INSERT INTO scheduled_task (id, name, scheduled_task_class, run_interval, default_run_interval, status, next_execution_time, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE scheduled_task_class = VALUES(scheduled_task_class), run_interval = VALUES(run_interval), status = VALUES(status), next_execution_time = VALUES(next_execution_time), updated_at = VALUES(updated_at)',
            [
                Uuid::randomBytes(),  // Correctly generate a binary UUID
                ExampleTask::getTaskName(),
                ExampleTask::class,
                ExampleTask::getDefaultInterval(),
                ExampleTask::getDefaultInterval(),
                ScheduledTaskDefinition::STATUS_SCHEDULED,
                $currentTime,
                $currentTime,
                $currentTime
            ]
        );
    }

    private function removeTask(): void
    {
        $this->container->get(Connection::class)->executeStatement(
            'DELETE FROM scheduled_task WHERE name = ?',
            [ExampleTask::getTaskName()]
        );
    }

    private function runTask(): void
    {
        /** @var ExampleTaskHandler $handler */
        $handler = $this->container->get(ExampleTaskHandler::class);
        $handler->handle(new \Shopware\Core\Framework\MessageQueue\Message\ScheduledTask\ScheduledTask());
    }
}
