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
use Reqser\Plugin\Service\ScheduledTask\ReqserNotificiationRemoval;
use Reqser\Plugin\Service\ScheduledTask\ReqserNotificiationRemovalHandler;

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
        if ($this->shouldRunTask('update')) {
            $this->runTask();
        }
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->scheduleTask();
        if ($this->shouldRunTask('activate')) {
            $this->runTask();
        }
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
        $nextExecutionTime = (new \DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');

        // Check if 'default_run_interval' column exists in 'scheduled_task' table since this row was added on Shopware 6.5
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM scheduled_task');
        $hasDefaultRunInterval = in_array('default_run_interval', $columns, true);

        $sql = 'INSERT INTO scheduled_task (id, name, scheduled_task_class, run_interval, ' .
            ($hasDefaultRunInterval ? 'default_run_interval, ' : '') .
            'status, next_execution_time, created_at, updated_at) VALUES (?, ?, ?, ?, ' .
            ($hasDefaultRunInterval ? '?, ' : '') .
            '?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE scheduled_task_class = VALUES(scheduled_task_class), run_interval = VALUES(run_interval), status = VALUES(status), next_execution_time = VALUES(next_execution_time), updated_at = VALUES(updated_at)';

        // Schedule ReqserNotificationRemoval
        $params = [
            Uuid::randomBytes(),
            ReqserNotificiationRemoval::getTaskName(),
            ReqserNotificiationRemoval::class,
            ReqserNotificiationRemoval::getDefaultInterval(),
        ];

        if ($hasDefaultRunInterval) {
            $params[] = ReqserNotificiationRemoval::getDefaultInterval();
        }

        $params = array_merge($params, [
            ScheduledTaskDefinition::STATUS_SCHEDULED,
            $nextExecutionTime,
            $currentTime,
            $currentTime
        ]);

        $connection->executeStatement($sql, $params);
    }


    private function removeTask(): void
    {
        $this->container->get(Connection::class)->executeStatement(
            'DELETE FROM scheduled_task WHERE name = ?',
            [ReqserNotificiationRemoval::getTaskName()]
        );
    }

    private function runTask(): void
    {
        /** @var ReqserNotificiationRemovalHandler $handler */
        $handler = $this->container->get(ReqserNotificiationRemovalHandler::class);
        $handler->run();
    }

    private function shouldRunTask(string $type = 'update'): bool
    {
        // Always return true to run notification removal task on update/activate
        return true;
    }

}
