<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service to read snippet data from JSON files without writing to database
 * Based on ReqserSnippetCrawlerHandler logic but returns data instead
 */
class ReqserSnippetApiService
{
    private Connection $connection;
    private LoggerInterface $logger;
    private ContainerInterface $container;
    private array $snippetSetMap = [];
    private string $baseLanguageSnippetSetID = '';

    public function __construct(
        Connection $connection,
        LoggerInterface $logger,
        ContainerInterface $container
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
        $this->container = $container;
    }

    /**
     * Collect all snippets from JSON files
     * 
     * @param bool $includeCoreFiles Whether to include Shopware core and SwagLanguagePack files
     * @return array Array of snippet data with metadata
     */
    public function collectSnippets(bool $includeCoreFiles = false): array
    {
        // Preload snippet set IDs
        $this->preloadSnippetSetIds();

        // Get the root directory of the Shopware installation
        $projectDir = $this->container->getParameter('kernel.project_dir');

        // Start searching for directories that contain Resources/snippet
        return $this->searchAndCollectSnippetFiles($projectDir, $includeCoreFiles);
    }

    /**
     * Preload snippet set IDs from database
     */
    private function preloadSnippetSetIds(): void
    {
        $sql = "SELECT id, iso, custom_fields FROM snippet_set WHERE custom_fields IS NOT NULL";
        $result = $this->connection->fetchAllAssociative($sql);

        foreach ($result as $row) {
            try {
                $customFields = json_decode($row['custom_fields'], true);
                if (isset($customFields['ReqserSnippetCrawl']['active']) && $customFields['ReqserSnippetCrawl']['active'] === true) {
                    $this->snippetSetMap[$row['iso']][] = [
                        'id' => (string) $row['id'],
                        'iso' => $row['iso']
                    ];
                    if (isset($customFields['ReqserSnippetCrawl']['baseLanguage']) && $customFields['ReqserSnippetCrawl']['baseLanguage'] === true) {
                        $this->baseLanguageSnippetSetID = (string) $row['id'];
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Reqser Plugin Error retrieving and reading custom_fields from snippet_set', [
                    'id' => $row['id'] ?? 'unknown',    
                    'iso' => $row['iso'] ?? 'unknown',
                    'custom_fields' => $row['custom_fields'] ?? 'unknown',
                    'message' => $e->getMessage(),
                    'file' => __FILE__, 
                    'line' => __LINE__,
                ]);
                continue;
            }
        }
    }

    /**
     * Search and collect snippet files
     * 
     * @param string $baseDirectory Base directory to start search
     * @param bool $includeCoreFiles Whether to include core files
     * @return array Collected snippets with metadata
     */
    private function searchAndCollectSnippetFiles(string $baseDirectory, bool $includeCoreFiles): array
    {
        $collectedData = [
            'snippetSets' => $this->snippetSetMap,
            'baseLanguageSetId' => $this->baseLanguageSnippetSetID,
            'files' => [],
            'snippets' => [],
            'includeCoreFiles' => $includeCoreFiles,
            'stats' => [
                'totalFiles' => 0,
                'totalSnippets' => 0,
                'skippedFiles' => 0,
                'errorFiles' => 0,
                'coreFilesSkipped' => 0
            ]
        ];

        $this->processDirectoryRecursively($baseDirectory, $collectedData);

        return $collectedData;
    }

    /**
     * Process directory recursively to find snippet files
     */
    private function processDirectoryRecursively(string $directory, array &$collectedData): void
    {
        try {
            $items = new \FilesystemIterator($directory, \FilesystemIterator::FOLLOW_SYMLINKS);
            
            foreach ($items as $item) {
                if ($item->isDir()) {
                    try {
                        $this->processSnippetFilesInDirectory($item->getPathname(), $collectedData);
                    } catch (\Exception $e) {
                        $this->logger->error('Reqser Plugin Error processing snippet directory', [
                            'path' => $item->getPathname(),
                            'message' => $e->getMessage(),
                            'file' => __FILE__, 
                            'line' => __LINE__,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Reqser Plugin Error accessing directory', [
                'directory' => $directory,
                'message' => $e->getMessage(),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
        }
    }

    /**
     * Process snippet files in a directory
     */
    private function processSnippetFilesInDirectory(string $directory, array &$collectedData): void
    {
        try {
            $directoryIterator = new \RecursiveDirectoryIterator($directory, \FilesystemIterator::FOLLOW_SYMLINKS);
            $iterator = new \RecursiveIteratorIterator($directoryIterator);
            $regexIterator = new \RegexIterator($iterator, '/^.+\.json$/i', \RecursiveRegexIterator::GET_MATCH);

            // Collect file paths and sort them
            $sortedFiles = [];
            foreach ($regexIterator as $file) {
                $sortedFiles[] = $file[0];
            }
            sort($sortedFiles);
                        
            foreach ($sortedFiles as $file) {
                try {
                    $filePath = $file;
                    $fileName = basename($filePath);

                    // Check if the filename contains at least two dots
                    if (substr_count($fileName, '.') < 2) {
                        $collectedData['stats']['skippedFiles']++;
                        continue;
                    }
                    
                    // Check if we should exclude core files
                    if (!$collectedData['includeCoreFiles']) {
                        if (strpos($filePath, '/custom/plugins/SwagLanguagePack/src/Resources/snippet/') !== false || strpos($filePath, '/vendor/shopware/') !== false) {
                            // Exclude SwagLanguagePack and core files when includeCoreFiles is false
                            $collectedData['stats']['skippedFiles']++;
                            $collectedData['stats']['coreFilesSkipped']++;
                            continue;
                        }
                    }

                    $snippetSetInfo = $this->getSnippetSetInfoFromFilePath($filePath);
                    if ($snippetSetInfo === null || count($snippetSetInfo) === 0) {
                        $collectedData['stats']['skippedFiles']++;
                        continue;
                    }

                    $content = file_get_contents($filePath);
                    
                    if ($content === false || empty(trim($content))) {
                        $collectedData['stats']['skippedFiles']++;
                        continue;
                    }
                    
                    $snippets = json_decode($content, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $collectedData['stats']['errorFiles']++;
                        continue;
                    }

                    // Add file information
                    $fileData = [
                        'path' => $filePath,
                        'fileName' => $fileName,
                        'snippetSets' => $snippetSetInfo,
                        'snippetCount' => 0
                    ];

                    $fileSnippets = [];
                    foreach ($snippets as $document => $value) {
                        $this->processSnippet((string) $document, $value, $snippetSetInfo, $fileSnippets);
                    }

                    $fileData['snippetCount'] = count($fileSnippets);
                    $collectedData['files'][] = $fileData;
                    $collectedData['snippets'] = array_merge($collectedData['snippets'], $fileSnippets);
                    $collectedData['stats']['totalFiles']++;
                    $collectedData['stats']['totalSnippets'] += count($fileSnippets);

                } catch (\Exception $e) {
                    $collectedData['stats']['errorFiles']++;
                    $this->logger->error('Reqser Plugin Error processing file', [
                        'file' => $filePath ?? 'unknown',
                        'message' => $e->getMessage(),
                        'log_file' => __FILE__, 
                        'line' => __LINE__,
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Reqser Plugin Error processing directory %s: %s', $directory, $e->getMessage()), ['file' => __FILE__, 'line' => __LINE__]);
        }
    }

    /**
     * Process a snippet and add to collection
     */
    private function processSnippet(string $key, $value, array $snippetSetInfo, array &$fileSnippets): void
    {
        if (is_array($value)) {
            foreach ($value as $subKey => $subValue) {
                $newKey = $key . '.' . (string) $subKey;
                $this->processSnippet((string) $newKey, $subValue, $snippetSetInfo, $fileSnippets);
            }
        } elseif (is_string($value)) {
            foreach ($snippetSetInfo as $setInfo) {
                $fileSnippets[] = [
                    'key' => $key,
                    'value' => $value,
                    'snippetSetId' => $setInfo['id'],
                    'snippetSetIso' => $setInfo['iso']
                ];
            }
        }
    }

    /**
     * Get snippet set info from file path
     * 
     * @param string $filePath
     * @return array|null
     */
    private function getSnippetSetInfoFromFilePath(string $filePath): ?array
    {
        $fileName = pathinfo($filePath, PATHINFO_BASENAME);
        $parts = explode('.', $fileName);
        $iso = $parts[count($parts) - 2]; // Get the second last part
        
        return ($this->snippetSetMap[$iso] ?? null);
    }
}

