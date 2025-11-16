<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service to read snippet data from JSON files without writing to database
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
     * Collect all snippets from JSON files for a specific snippet set
     *
     * @param string $snippetSetId The snippet set ID to collect snippets for
     * @param bool $includeCoreFiles Whether to include Shopware core and SwagLanguagePack files
     * @return array Array of snippet data with metadata
     */
    public function collectSnippets(string $snippetSetId, bool $includeCoreFiles = false): array
    {
        // Get snippet set info from database
        $snippetSetInfo = $this->getSnippetSetInfo($snippetSetId);

        if (!$snippetSetInfo) {
            return [
                'error' => 'Snippet set not found',
                'snippetSetId' => $snippetSetId
            ];
        }

        // Get the root directory of the Shopware installation
        $projectDir = $this->container->getParameter('kernel.project_dir');

        // Start searching for snippet files
        return $this->searchAndCollectSnippetFiles($projectDir, $snippetSetInfo, $includeCoreFiles, $projectDir);
    }

    /**
     * Get snippet set information from database
     * 
     * @param string $snippetSetId
     * @return array|null
     */
    private function getSnippetSetInfo(string $snippetSetId): ?array
    {
        try {
            $sql = "SELECT HEX(id) as id, iso, name FROM snippet_set WHERE HEX(id) = :id";
            $result = $this->connection->fetchAssociative($sql, ['id' => $snippetSetId]);
            
            if (!$result) {
                return null;
            }
            
            return [
                'id' => (string) $result['id'],
                'iso' => $result['iso'],
                'name' => $result['name'] ?? $result['iso']
            ];
        } catch (\Exception $e) {
            $this->logger->error('Reqser Plugin Error retrieving snippet_set info', [
                'snippetSetId' => $snippetSetId,
                'message' => $e->getMessage(),
                'file' => __FILE__, 
                'line' => __LINE__,
            ]);
            return null;
        }
    }

    /**
     * Search and collect snippet files
     *
     * @param string $baseDirectory Base directory to start search
     * @param array $snippetSetInfo Snippet set information
     * @param bool $includeCoreFiles Whether to include core files
     * @param string $projectDir Project root directory for relative path calculation
     * @return array Collected snippets with metadata
     */
    private function searchAndCollectSnippetFiles(string $baseDirectory, array $snippetSetInfo, bool $includeCoreFiles, string $projectDir): array
    {
        $collectedData = [
            'snippetSet' => $snippetSetInfo,
            'files' => [],
            'includeCoreFiles' => $includeCoreFiles,
            'projectDir' => $projectDir, // Store for use in file processing
            'stats' => [
                'totalFiles' => 0,
                'totalSnippets' => 0,
                'errorFiles' => 0
            ]
        ];

        $this->processDirectoryRecursively($baseDirectory, $collectedData);
        
        // Remove projectDir from final output (only needed internally)
        unset($collectedData['projectDir']);

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
                        continue;
                    }
                    
                    // Check if we should exclude core files
                    if (!$collectedData['includeCoreFiles']) {
                        if (strpos($filePath, '/custom/plugins/SwagLanguagePack/src/Resources/snippet/') !== false || strpos($filePath, '/vendor/shopware/') !== false) {
                            // Exclude SwagLanguagePack and core files when includeCoreFiles is false
                            continue;
                        }
                    }

                    // Check if this file matches the requested ISO code
                    $fileIso = $this->getIsoFromFilePath($filePath);
                    if ($fileIso !== $collectedData['snippetSet']['iso']) {
                        continue;
                    }

                    $content = file_get_contents($filePath);
                    
                    if ($content === false || empty(trim($content))) {
                        continue;
                    }
                    
                    $snippets = json_decode($content, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $collectedData['stats']['errorFiles']++;
                        continue;
                    }

                    // Flatten nested snippets
                    $flatSnippets = [];
                    foreach ($snippets as $document => $value) {
                        $this->flattenSnippet((string) $document, $value, $flatSnippets);
                    }

                            // Convert absolute path to relative path
                            $relativePath = str_replace($collectedData['projectDir'], '', $filePath);
                            $relativePath = ltrim($relativePath, '/\\'); // Remove leading slashes
                            
                            // Add file with all its snippets
                            $collectedData['files'][] = [
                                'fileName' => $fileName,
                                'filePath' => $relativePath,
                                'snippets' => $flatSnippets
                            ];
                    
                    $collectedData['stats']['totalFiles']++;
                    $collectedData['stats']['totalSnippets'] += count($flatSnippets);

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
     * Flatten nested snippet structure into key-value pairs
     */
    private function flattenSnippet(string $key, $value, array &$flatSnippets): void
    {
        if (is_array($value)) {
            foreach ($value as $subKey => $subValue) {
                $newKey = $key . '.' . (string) $subKey;
                $this->flattenSnippet((string) $newKey, $subValue, $flatSnippets);
            }
        } elseif (is_string($value)) {
            $flatSnippets[$key] = $value;
        }
    }

    /**
     * Extract ISO code from file path
     * 
     * @param string $filePath
     * @return string|null
     */
    private function getIsoFromFilePath(string $filePath): ?string
    {
        $fileName = pathinfo($filePath, PATHINFO_BASENAME);
        $parts = explode('.', $fileName);
        // Get the second last part (e.g., "en-GB" from "snippets.en-GB.json")
        return $parts[count($parts) - 2] ?? null;
    }
}

