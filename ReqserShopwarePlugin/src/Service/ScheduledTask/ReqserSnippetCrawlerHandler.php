<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\Snippet\Files\SnippetFileCollectionFactory;
use Shopware\Core\Framework\Uuid\Uuid;

class ReqserSnippetCrawlerHandler extends ScheduledTaskHandler
{
    private Connection $connection;
    private LoggerInterface $logger;
    private ContainerInterface $container;
    private SnippetFileCollectionFactory $snippetFileCollectionFactory;
    private array $snippetSetMap = [];

    public function __construct(EntityRepository $scheduledTaskRepository, Connection $connection, LoggerInterface $logger, ContainerInterface $container, SnippetFileCollectionFactory $snippetFileCollectionFactory)
    {
        parent::__construct($scheduledTaskRepository);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->container = $container;
        $this->snippetFileCollectionFactory = $snippetFileCollectionFactory;
    }

    public function run(): void
    {
        // Preload snippet set IDs
        $this->preloadSnippetSetIds();

        // Get the root directory of the Shopware installation
        $projectDir = $this->container->getParameter('kernel.project_dir');

        // Start searching for directories that contain Resources/snippet
        $this->searchAndProcessSnippetDirectories($projectDir);
    }

    private function preloadSnippetSetIds(): void
    {
        $sql = "SELECT id, iso FROM snippet_set";
        $result = $this->connection->fetchAllAssociative($sql);

        foreach ($result as $row) {
            $this->snippetSetMap[$row['iso']] = (string) $row['id'];
        }
    }

    private function searchAndProcessSnippetDirectories(string $baseDirectory): void
    {
        $this->processDirectoryRecursively($baseDirectory);
    }

    private function processDirectoryRecursively(string $directory): void
    {
        try {
            $items = new \FilesystemIterator($directory, \FilesystemIterator::FOLLOW_SYMLINKS);

            foreach ($items as $item) {
                if ($item->isDir()) {
                    try {
                        $this->processSnippetFilesInDirectory($item->getPathname());
                    } catch (\Exception $e) {
                        // Log the error message and continue with the next directory
                        $this->logger->error('Reqser Plugin Error processing snippet directory', [
                            'path' => $item->getPathname(),
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Log the error message and continue with the next directory
            $this->logger->error('Reqser Plugin Error accessing directory', [
                'directory' => $directory,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function processSnippetFilesInDirectory(string $directory): void
    {
        try {
            $directoryIterator = new \RecursiveDirectoryIterator($directory, \FilesystemIterator::FOLLOW_SYMLINKS);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);
            $regexIterator = new \RegexIterator($iterator, '/^.+\.json$/i', \RecursiveRegexIterator::GET_MATCH);

            foreach ($regexIterator as $file) {
                try {
                    $filePath = $file[0];
                    $fileName = basename($filePath);

                    // Check if the filename contains at least two dots
                    if (substr_count($fileName, '.') < 2) {
                        //$this->logger->warning(sprintf('Skipping file with insufficient dots: %s', $filePath));
                        continue;
                    }

                    //$this->logger->info(sprintf('Working on file: %s', $filePath));

                    $content = file_get_contents($filePath);
                    $snippets = json_decode($content, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->logger->error(sprintf('Reqser Plugin Invalid JSON in file %s: %s', $filePath, json_last_error_msg()));
                        continue;
                    }

                    foreach ($snippets as $document => $value) {
                        //$this->logger->info(sprintf('Found snippet: key = %s, value = %s', $document, json_encode($value)));
                        $this->processSnippet($document, $value, $filePath);
                    }
                } catch (\Exception $e) {
                    // Log the error message and continue with the next file
                    $this->logger->error('Reqser Plugin Error processing file', [
                        'file' => $filePath,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Reqser Plugin Error processing directory %s: %s', $directory, $e->getMessage()));
        }
    }

    private function processSnippet(string $key, $value, string $filePath): void
    {
        if (is_array($value)) {
            foreach ($value as $subKey => $subValue) {
                $newKey = $key . '.' . $subKey;
                $this->processSnippet($newKey, $subValue, $filePath);
            }
        } elseif (is_string($value)) {
            $snippetSetId = $this->getSnippetSetIdFromFilePath($filePath);
            if ($snippetSetId !== null && $snippetSetId != '') {
                $this->addSnippetIfNotExists($key, $value, $snippetSetId);
            } else {
                //$this->logger->error(sprintf('Snippet set ID not found for file %s', $filePath));
                //$this->logger->error(sprintf('Snippet set ID not found for file %s', json_encode($this->snippetSetMap)));
            }

        } else {
            //$this->logger->error(sprintf('Invalid snippet value for key %s: must be a string or array', $key));
        }
    }

    private function getSnippetSetIdFromFilePath(string $filePath): string|null
    {
        $fileName = pathinfo($filePath, PATHINFO_BASENAME); // Get the basename of the file
        $parts = explode('.', $fileName); // Split by periods
        $iso = $parts[count($parts) - 2]; // Get the second last part
        if (!isset($this->snippetSetMap[$iso])) {
            //$this->logger->error(sprintf('Snippet set ID not found for Filename &s ISO code %s', $fileName, $iso));
        }
        return (string) ($this->snippetSetMap[$iso] ?? null); // Default to 1 if the ISO code is not found
    }

    private function addSnippetIfNotExists(string $key, string $value, string $snippetSetId): void
    {
        $existingSnippet = $this->connection->fetchAssociative('SELECT id, author, value FROM snippet WHERE `translation_key` = ? AND `snippet_set_id` = ?', [$key, $snippetSetId]);

        if (!$existingSnippet) {
            try {
                $this->connection->insert('snippet', [
                    'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
                    'translation_key' => $key,
                    'value' => $value,
                    'author' => 'reqser_plugin_crawler', // or appropriate author value
                    'snippet_set_id' => $snippetSetId,
                    'custom_fields' => null, // or appropriate custom fields
                    'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                    'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Reqser Plugin Error inserting snippet', [
                    'key' => $key,
                    'value' => $value,
                    'snippet_set_id' => $snippetSetId,
                    'exception' => $e->getMessage(),
                ]);
            }
        } elseif ($existingSnippet['author'] == 'reqser_plugin_crawler') {
            // Check if the value is changed and the author is the plugin itself, so it can be overwritten in case a module is updated
            if ($existingSnippet['value'] != $value) {
                try {
                    $this->connection->update('snippet', [
                        'value' => $value,
                        'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                    ], [
                        'id' => $existingSnippet['id'],
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('Reqser Plugin Error updating snippet', [
                        'key' => $key,
                        'value' => $value,
                        'snippet_set_id' => $snippetSetId,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public static function getHandledMessages(): iterable
    {
        return [ReqserSnippetCrawler::class];
    }
}
