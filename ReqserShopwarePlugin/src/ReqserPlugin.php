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
use Reqser\Plugin\Service\ScheduledTask\ReqserSnippetCrawler;
use Reqser\Plugin\Service\ScheduledTask\ReqserSnippetCrawlerHandler;
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
        $nextExecutionTime = (new \DateTime())->format('Y-m-d H:i:s');

        // Check if 'default_run_interval' column exists in 'scheduled_task' table since this row was added on Shopware 6.5
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM scheduled_task');
        $hasDefaultRunInterval = in_array('default_run_interval', $columns, true);

        $sql = 'INSERT INTO scheduled_task (id, name, scheduled_task_class, run_interval, ' .
            ($hasDefaultRunInterval ? 'default_run_interval, ' : '') .
            'status, next_execution_time, created_at, updated_at) VALUES (?, ?, ?, ?, ' .
            ($hasDefaultRunInterval ? '?, ' : '') .
            '?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE scheduled_task_class = VALUES(scheduled_task_class), run_interval = VALUES(run_interval), status = VALUES(status), next_execution_time = VALUES(next_execution_time), updated_at = VALUES(updated_at)';

        $params = [
            Uuid::randomBytes(),
            ReqserSnippetCrawler::getTaskName(),
            ReqserSnippetCrawler::class,
            ReqserSnippetCrawler::getDefaultInterval(),
        ];

        if ($hasDefaultRunInterval) {
            $params[] = ReqserSnippetCrawler::getDefaultInterval();
        }

        $params = array_merge($params, [
            ScheduledTaskDefinition::STATUS_SCHEDULED,
            $nextExecutionTime,
            $currentTime,
            $currentTime
        ]);

        $connection->executeStatement($sql, $params);

        // Repeat for ReqserNotificationRemoval
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
            [ReqserSnippetCrawler::getTaskName()]
        );
        $this->container->get(Connection::class)->executeStatement(
            'DELETE FROM scheduled_task WHERE name = ?',
            [ReqserNotificiationRemoval::getTaskName()]
        );
    }

    private function runTask(): void
    {
        /** @var ReqserSnippetCrawlerHandler $handler */
        $handler = $this->container->get(ReqserSnippetCrawlerHandler::class);
        $handler->run();

        /** @var ReqserNotificiationRemovalHandler $handler */
        $handler = $this->container->get(ReqserNotificiationRemovalHandler::class);
        $handler->run();

    }

    private function shouldRunTask(string $type = 'update'): bool
    {
        try {
            $connection = $this->container->get(Connection::class);
            $sql = "SELECT id, iso, custom_fields FROM snippet_set WHERE custom_fields IS NOT NULL";
            $result = $connection->fetchAllAssociative($sql);

            foreach ($result as $row) {
                try {
                    $customFields = json_decode($row['custom_fields'], true);
                    if (isset($customFields['ReqserSnippetCrawl']) 
                        && isset($customFields['ReqserSnippetCrawl']['active']) 
                        && $customFields['ReqserSnippetCrawl']['active'] === true
                        && isset($customFields['ReqserSnippetCrawl']['baseLanguage']) 
                        && $customFields['ReqserSnippetCrawl']['baseLanguage'] === true
                        && isset($customFields['ReqserSnippetCrawl']['run_on_'.$type]) 
                        && $customFields['ReqserSnippetCrawl']['run_on_'.$type] === true
                    ) {
                        return true;
                    } else {
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        } catch (\Exception $e) {
            return false;
        }
        return false;
    }

}
