<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduleTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\Handler\AbstractMessageHandler;
use Shopware\Core\Framework\MessageQueue\Message\Message;

class ReqserSnippetCrawlerHandler extends AbstractMessageHandler
{
    private LoggerInterface $logger;
    private Connection $connection;

    public function __construct(LoggerInterface $logger, Connection $connection)
    {
        $this->logger = $logger;
        $this->connection = $connection;
    }

    public static function getHandledMessages(): iterable
    {
        return [
            ReqserSnippetCrawler::class,
        ];
    }

    public function handle(Message $message): void
    {
        $this->logger->info('ReqserSnippetCrawler is being executed.');

        $snippetBaseDir = __DIR__ . '/../../../Resources/snippets/';
        $this->processSnippetDirectory($snippetBaseDir);
    }

    private function processSnippetDirectory(string $directory): void
    {
        $files = glob($directory . '/**/*.json', GLOB_BRACE);
        foreach ($files as $file) {
            $locale = basename(dirname($file));
            $snippets = json_decode(file_get_contents($file), true);
            if (is_array($snippets)) {
                foreach ($snippets as $key => $value) {
                    $this->insertSnippet($key, $value, $locale);
                }
            }
        }
    }

    private function insertSnippet(string $key, string $value, string $locale): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM snippet WHERE `key` = :key AND `locale` = :locale',
            ['key' => $key, 'locale' => $locale]
        );

        if (!$exists) {
            $this->connection->insert('snippet', [
                'key' => $key,
                'value' => $value,
                'locale' => $locale,
            ]);
        }
    }
}
