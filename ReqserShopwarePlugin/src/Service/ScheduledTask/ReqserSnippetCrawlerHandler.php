<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\ScheduledTask;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\App\ShopId\ShopIdProvider;
use Shopware\Core\Framework\Context;

class ReqserSnippetCrawlerHandler extends ScheduledTaskHandler
{
    private Connection $connection;
    private LoggerInterface $logger;
    private ContainerInterface $container;
    private array $snippetSetMap = [];
    private string $baseLanguageSnippetSetID = '';
    private ShopIdProvider $shopIdProvider;
    private $webhookUrl = 'https://reqser.com/app/shopware/webhook/plugin';

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        Connection $connection,
        LoggerInterface $logger,
        ContainerInterface $container,
        ShopIdProvider $shopIdProvider
    ) {
        parent::__construct($scheduledTaskRepository);
        $this->connection = $connection;
        $this->logger = $logger;
        $this->container = $container;
        $this->shopIdProvider = $shopIdProvider;
    }

    public function run(): void
    {
        // Preload snippet set IDs
        $this->preloadSnippetSetIds();

        // Get the root directory of the Shopware installation
        $projectDir = $this->container->getParameter('kernel.project_dir');

        // Start searching for directories that contain Resources/snippet
        $this->searchAndProcessSnippetDirectories($projectDir);

        //make sure all translations are created
        $this->createAllNecessarySnippetTranslations();
    }


    private function preloadSnippetSetIds(): void
    {
        $sql = "SELECT id, iso, custom_fields FROM snippet_set WHERE custom_fields IS NOT NULL";
        $result = $this->connection->fetchAllAssociative($sql);

        foreach ($result as $row) {
            try {
                $customFields = json_decode($row['custom_fields'], true);
                if (isset($customFields['ReqserSnippetCrawl']['active']) && $customFields['ReqserSnippetCrawl']['active'] === true) {
                    $this->snippetSetMap[$row['iso']][] = (string) $row['id'];
                    if (isset($customFields['ReqserSnippetCrawl']['baseLanguage']) && $customFields['ReqserSnippetCrawl']['baseLanguage'] === true) {
                        $this->baseLanguageSnippetSetID = (string) $row['id'];
                    }
                }
            } catch (\Exception $e) {
                  // Log the error message and continue with the next directory
                  $this->logger->error('Reqser Plugin Error retrieving and read custom_fields from snippet_set', [
                    'id' => $row['id'] ?? 'unknown',    
                    'iso' => $row['iso'] ?? 'unknown',
                    'custom_fields' => $row['custom_fields'] ?? 'unknown',
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
        }
    }

    private function searchAndProcessSnippetDirectories(string $baseDirectory): void
    {
        $this->processDirectoryRecursively($baseDirectory);
    }

    //Can be used for Debuging to Send a Notification to the Admin
    private function sendAdminNotification(string $message): void
    {
        //Put this anywhere to get a notification in the Admin
        //$this->sendAdminNotification('Reqser Snippet Crawler run Z'.__LINE__);

        $context = Context::createDefaultContext();

        /** @var EntityRepository $notificationRepository */
        $notificationRepository = $this->container->get('notification.repository');

        $notificationRepository->create([
            [
                'id' => Uuid::randomHex(),
                'status' => 'info',
                'message' => $message,
                'adminOnly' => true,
                'requiredPrivileges' => [],
            ],
        ], $context);
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
        } catch (\Throwable $e) {
            // Log the error message and continue with the next directory
            if (method_exists($this->logger, 'error')) {
                $this->logger->error('Reqser Plugin Error accessing directory', [
                    'directory' => $directory,
                    'message' => $e->getMessage(),
                ]);
            }
    
            // Send error to webhook
            $this->sendErrorToWebhook([
                'type' => 'error',
                'function' => 'processDirectoryRecursively',
                'directory' => $directory ?? 'unknown',
                'message' => $e->getMessage() ?? 'unknown',
                'trace' => $e->getTraceAsString() ?? 'unknown',
                'timestamp' => date('Y-m-d H:i:s'),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
        }
    }

    private function sendErrorToWebhook(array $data): void
        {
            $url = $this->webhookUrl;
            //Add Standard Data host and shop_id
            $data['host'] = $_SERVER['HTTP_HOST'] ?? 'unknown';
            $data['shopId'] = $this->shopIdProvider->getShopId() ?? 'unknown';

            $payload = json_encode($data);

            if (
                function_exists('curl_init') &&
                function_exists('curl_setopt') &&
                function_exists('curl_exec') &&
                function_exists('curl_close')
            ) {
                $ch = curl_init($url);

                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload)
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
                $result = curl_exec($ch);
    
                if ($result === false) {
                    // Optionally handle errors here
                    $error = curl_error($ch);
                    // You can log this error if necessary
                }
    
                curl_close($ch);
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
                    } elseif (strpos($filePath, '/custom/plugins/SwagLanguagePack/src/Resources/snippet/') !== false || strpos($filePath, '/vendor/shopware/') !== false) {
                        //JorisK Here we exclude all SwagLangaugePack for performance reasons, they are all translated by Shopware already and as long as they are update should not be an issue, but if so remove the continue here and they will also be loaded into snipped table (Attention performance issue)
                        //Also all core files are excluded, as they should be handled to Shopware default language Pack
                        //$this->logger->info(sprintf('Skipped file: %s', $filePath));
                        continue;
                    }

                    //$this->logger->info(sprintf('Working on file: %s', $filePath));

                    $content = file_get_contents($filePath);
                    $snippets = json_decode($content, true);

                    if (empty(trim($content))) {
                        continue;
                    }

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->logger->error(sprintf('Reqser Plugin Invalid JSON in file %s: %s', $filePath, json_last_error_msg()));
                        continue;
                    }

                    foreach ($snippets as $document => $value) {
                        //$this->logger->info(sprintf('Found snippet: key = %s, value = %s', $document, json_encode($value)));
                        $this->processSnippet((string) $document, $value, $filePath);
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

    /**
     * Process the Snippet
     * 
     * @param string $key
     * @param mixed $value
     * @param string $filePath
     * @return void
     */ 
    private function processSnippet(string $key, $value, string $filePath): void
    {
        if (is_array($value)) {
            foreach ($value as $subKey => $subValue) {
                $newKey = $key . '.' . (string) $subKey;
                $this->processSnippet((string) $newKey, $subValue, $filePath);
            }
        } elseif (is_string($value)) {
            $snippetSetId = $this->getSnippetSetIdFromFilePath($filePath);
            if ($snippetSetId !== null && count($snippetSetId) > 0) {
                foreach ($snippetSetId as $id) {
                    $this->addSnippetIfNotExists($key, $value, $id);
                }
            } else {
                //$this->logger->error(sprintf('Snippet set ID not found for file %s', $filePath));
                //$this->logger->error(sprintf('Snippet set ID not found for file %s', json_encode($this->snippetSetMap)));
            }

        } else {
            //$this->logger->error(sprintf('Invalid snippet value for key %s: must be a string or array', $key));
        }
    }


    /**
     * Get the Snippet Set IDs from the File Path
     * 
     * @param string $filePath
     * @return array|null
     */
    private function getSnippetSetIdFromFilePath(string $filePath): array|null
    {
        $fileName = pathinfo($filePath, PATHINFO_BASENAME); // Get the basename of the file
        $parts = explode('.', $fileName); // Split by periods
        $iso = $parts[count($parts) - 2]; // Get the second last part
        if (!isset($this->snippetSetMap[$iso])) {
            //$this->logger->error(sprintf('Snippet set ID not found for Filename &s ISO code %s', $fileName, $iso));
        }
        return ($this->snippetSetMap[$iso] ?? null); // Default to 1 if the ISO code is not found
    }

    private function addSnippetIfNotExists(string $key, string $value, string $snippetSetId): void
    {
        $existingSnippet = $this->connection->fetchAssociative('SELECT id, author, value, created_at, updated_at FROM snippet WHERE `translation_key` = ? AND `snippet_set_id` = ?', [$key, $snippetSetId]);

        if (!$existingSnippet) {
            try {
                $timespan = (new \DateTime())->format('Y-m-d H:i:s');
                $this->connection->insert('snippet', [
                    'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
                    'translation_key' => $key,
                    'value' => $value,
                    'author' => 'reqser_plugin_crawler', // or appropriate author value
                    'snippet_set_id' => $snippetSetId,
                    'custom_fields' => null, // or appropriate custom fields
                    'created_at' => $timespan,
                    'updated_at' => $timespan,
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
                    if ($existingSnippet['created_at'] == $existingSnippet['updated_at']) {
                        $timespan = (new \DateTime())->format('Y-m-d H:i:s');
                        $this->connection->update('snippet', [
                            'value' => $value,
                            'created_at' => $timespan, // Update the created_at timestamp to ensure manual changes are not overwritten
                            'updated_at' => $timespan,
                        ], [
                            'id' => $existingSnippet['id'],
                        ]);
                    }
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

    private function createAllNecessarySnippetTranslations(): void
    {
        $this->logger->info(sprintf('ReqserApp createAllNecessarySnippetTranslations Working'));
            if ($this->baseLanguageSnippetSetID != ''){
                //We need to make sure the entry exist for all langauges used in any sales channel, if not we have to create the entry empty so it can be handled via Admin API
                $sql = "SELECT DISTINCT snippet_set_id FROM sales_channel_domain";
                $result = $this->connection->fetchAllAssociative($sql);

            if (count($result) > 0) {
                $snippet_set_array = [];
                foreach ($result as $row) {
                    if ($row['snippet_set_id'] == $this->baseLanguageSnippetSetID) continue;
                    // Check if the snippet_set_id exists in any of the ISO's snippet arrays
                    foreach ($this->snippetSetMap as $snippetIdsArray) {
                        if (in_array($row['snippet_set_id'], $snippetIdsArray)) {
                            $snippet_set_array[] = $row['snippet_set_id'];
                            break; // Exit inner loop once found
                        }
                    }
                }
                if (count($snippet_set_array) > 0){
                    //$this->logger->info(sprintf('ReqserApp createAllNecessarySnippetTranslations Working Snippet Array bigger than 0'));
                    //now get all the snippet keys from our base language and make sure the entries exist in all other languages that are active handled by reqser
                    $sql = "SELECT DISTINCT translation_key FROM snippet WHERE snippet_set_id = ?";
                    $result = $this->connection->fetchAllAssociative($sql, [$this->baseLanguageSnippetSetID]);
                    if (count($result) > 0) {
                        //$this->logger->info(sprintf('ReqserApp createAllNecessarySnippetTranslations Working call to snippet than 0'));
                        $snippet_key_array = [];
                        foreach ($result as $row) {
                            $snippet_key_array[] = $row['translation_key'];
                        }
                        if (count($snippet_key_array) > 0){
                            //$this->logger->info(sprintf('ReqserApp createAllNecessarySnippetTranslations Working snippet_key_array bigger than 0'));
                            foreach ($snippet_set_array as $snippet_set_id) {
                                //$this->logger->info(sprintf('ReqserApp createAllNecessarySnippetTranslations Working on Snippet Set ID: %s', $snippet_set_id));
                                foreach ($snippet_key_array as $snippet_key) {
                                    //$this->logger->info(sprintf('ReqserApp createAllNecessarySnippetTranslations Working on Snippet Key: %s', $snippet_key));
                                    $existingSnippet = $this->connection->fetchAssociative('SELECT id FROM snippet WHERE `translation_key` = ? AND `snippet_set_id` = ?', [$snippet_key, $snippet_set_id]);
                                    if (!$existingSnippet) {
                                        //$this->logger->info(sprintf('ReqserApp createAllNecessarySnippetTranslations Working fond a key not existing in all languages: %s', $snippet_key));
                                        try {
                                            $this->connection->insert('snippet', [
                                                'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
                                                'translation_key' => $snippet_key,
                                                'value' => '',
                                                'author' => 'reqser_plugin_crawler', // or appropriate author value
                                                'snippet_set_id' => $snippet_set_id,
                                                'custom_fields' => null, // or appropriate custom fields
                                                'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                                                'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                                            ]);
                                        } catch (\Exception $e) {
                                            $this->logger->error('Reqser Plugin Error inserting snippet', [
                                                'key' => $snippet_key,
                                                'value' => '',
                                                'snippet_set_id' => $snippet_set_id,
                                                'exception' => $e->getMessage(),
                                            ]);
                                        }
                                    } else {
                                        //$this->logger->info(sprintf('ReqserApp createAllNecessarySnippetTranslations it does exist all fine', $snippet_key)); 
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public static function getHandledMessages(): iterable
    {
        return [ReqserSnippetCrawler::class];
    }
}
