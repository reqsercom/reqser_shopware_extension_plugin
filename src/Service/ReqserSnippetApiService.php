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
     * @param string|null $filePath Optional specific path to collect from (prevents scanning all folders)
     * @param bool $onlyCollectPath If true, only return file paths without snippet data
     * @return array Array of snippet data with metadata
     */
    public function collectSnippets(
        string $snippetSetId, 
        bool $includeCoreFiles = false,
        ?string $filePath = null,
        bool $onlyCollectPath = false
    ): array
    {
        // Get snippet set info from database
        $snippetSetInfo = $this->getSnippetSetInfo($snippetSetId);

        if (!$snippetSetInfo) {
            return [
                'error' => 'Snippet set not found',
                'message' => 'The snippet set with ID ' . $snippetSetId . ' was not found'
            ];
        }

        // Get the root directory of the Shopware installation
        $projectDir = $this->container->getParameter('kernel.project_dir');

        // Determine the starting directory for collection
        $searchDirectory = $projectDir;
        $isSpecificFile = false;
        
        if ($filePath !== null) {
            // If a specific path is provided, use it as the starting point
            // Support both absolute paths and relative paths from project root
            if (str_starts_with($filePath, '/')) {
                $searchDirectory = $projectDir . $filePath;
            } else {
                $searchDirectory = $projectDir . '/' . $filePath;
            }
            
            // Validate that the path exists
            if (!file_exists($searchDirectory)) {
                return [
                    'error' => 'Specified path does not exist',
                    'message' => 'The path ' . $filePath . ' does not exist'
                ];
            }
            
            // Check if this is a file or directory
            if (is_file($searchDirectory)) {
                $isSpecificFile = true;
            }
        }

        // If a specific file is provided, process only that file
        if ($isSpecificFile) {
            return $this->processSingleFile(
                $searchDirectory,
                $snippetSetInfo,
                $includeCoreFiles,
                $projectDir,
                $onlyCollectPath
            );
        }

        // Start searching for snippet files in directory
        return $this->searchAndCollectSnippetFiles(
            $searchDirectory, 
            $snippetSetInfo, 
            $includeCoreFiles, 
            $projectDir,
            $onlyCollectPath
        );
    }

    /**
     * Process a single specific file
     *
     * @param string $filePath Full path to the specific file
     * @param array $snippetSetInfo Snippet set information
     * @param bool $includeCoreFiles Whether to include core files
     * @param string $projectDir Project root directory for relative path calculation
     * @param bool $onlyCollectPath If true, only collect file path without snippet data
     * @return array Collected snippet data
     */
    private function processSingleFile(
        string $filePath,
        array $snippetSetInfo,
        bool $includeCoreFiles,
        string $projectDir,
        bool $onlyCollectPath = false
    ): array
    {
        $collectedData = [
            'snippetSet' => $snippetSetInfo,
            'includeCoreFiles' => $includeCoreFiles,
            'stats' => [
                'totalFiles' => 0,
                'totalSnippets' => 0,
                'errorFiles' => 0,
                'coreFiles' => 0,
                'customFiles' => 0
            ],
            'files' => []
        ];

        try {
            $fileName = basename($filePath);

            // Check if the filename contains at least two dots (e.g., messages.de-DE.json)
            if (substr_count($fileName, '.') < 2) {
                return [
                    'error' => 'Invalid snippet file format',
                    'message' => 'Snippet files must have format like "messages.{iso}.json"',
                    'fileName' => $fileName
                ];
            }

            // Check if we should exclude core files
            if (!$includeCoreFiles && $this->isCoreFile($filePath)) {
                return [
                    'message' => 'File is a core file and includeCoreFiles is false',
                    'filePath' => $filePath,
                    'stats' => $collectedData['stats']
                ];
            }

            // Check if this file matches the requested ISO code
            $fileIso = $this->getIsoFromFilePath($filePath);
            $expectedIso = $snippetSetInfo['iso'];

            if (!$this->isIsoMatch($fileIso, $expectedIso)) {
                return [
                    'message' => 'File ISO code does not match requested snippet set',
                    'fileIso' => $fileIso,
                    'expectedIso' => $expectedIso,
                    'filePath' => $filePath,
                    'stats' => $collectedData['stats']
                ];
            }

            // Convert to clean relative path
            $realPath = realpath($filePath);
            $realProjectDir = realpath($projectDir);
            $relativePath = str_replace($realProjectDir, '', $realPath);
            $relativePath = str_replace('\\', '/', $relativePath);
            if (!str_starts_with($relativePath, '/')) {
                $relativePath = '/' . $relativePath;
            }

            $isCoreFile = $this->isCoreFile($relativePath);

            // If only collecting paths, skip reading file content
            if ($onlyCollectPath) {
                $collectedData['files'][] = [
                    'fileName' => $fileName,
                    'filePath' => $relativePath,
                    'isCoreFile' => $isCoreFile
                ];
                $collectedData['stats']['totalFiles']++;
                if ($isCoreFile) {
                    $collectedData['stats']['coreFiles']++;
                } else {
                    $collectedData['stats']['customFiles']++;
                }
                return $collectedData;
            }

            // Full collection mode: read and parse file content
            $content = file_get_contents($filePath);

            if ($content === false || empty(trim($content))) {
                return [
                    'error' => 'Unable to read file or file is empty',
                    'filePath' => $relativePath,
                    'stats' => $collectedData['stats']
                ];
            }

            $snippets = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'error' => 'Invalid JSON in file',
                    'jsonError' => json_last_error_msg(),
                    'filePath' => $relativePath,
                    'stats' => $collectedData['stats']
                ];
            }

            // Flatten nested snippets
            $flatSnippets = [];
            foreach ($snippets as $document => $value) {
                $this->flattenSnippet((string) $document, $value, $flatSnippets);
            }

            // Add file with all its snippets
            $collectedData['files'][] = [
                'fileName' => $fileName,
                'filePath' => $relativePath,
                'snippets' => $flatSnippets,
                'isCoreFile' => $isCoreFile
            ];

            $collectedData['stats']['totalFiles']++;
            $collectedData['stats']['totalSnippets'] += count($flatSnippets);

            if ($isCoreFile) {
                $collectedData['stats']['coreFiles']++;
            } else {
                $collectedData['stats']['customFiles']++;
            }

        } catch (\Throwable $e) {
            // Return error without logging to prevent Shopware log pollution
            return [
                'error' => 'Error processing file',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'filePath' => $filePath,
                'stats' => $collectedData['stats']
            ];
        }

        return $collectedData;
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
        } catch (\Throwable $e) {
            // Return null without logging - error will be handled at controller level
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
     * @param bool $onlyCollectPath If true, only collect file paths without snippet data
     * @return array Collected snippets with metadata
     */
    private function searchAndCollectSnippetFiles(
        string $baseDirectory, 
        array $snippetSetInfo, 
        bool $includeCoreFiles, 
        string $projectDir,
        bool $onlyCollectPath = false
    ): array
    {
        $collectedData = [
            'snippetSet' => $snippetSetInfo,
            'includeCoreFiles' => $includeCoreFiles,
            'onlyCollectPath' => $onlyCollectPath,
            'projectDir' => $projectDir, // Store for use in file processing
            'stats' => [
                'totalFiles' => 0,
                'totalSnippets' => 0,
                'errorFiles' => 0,
                'coreFiles' => 0,
                'customFiles' => 0
            ],
            'files' => []
        ];

        $this->processDirectoryRecursively($baseDirectory, $collectedData);
        
        // Remove internal fields from final output (only needed internally)
        unset($collectedData['projectDir']);
        unset($collectedData['onlyCollectPath']);

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
                    } catch (\Throwable $e) {
                        // Silently skip problematic directories - they'll be tracked in errorFiles stats
                        $collectedData['stats']['errorFiles']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently skip inaccessible directories - prevents log pollution
            // Error will be reflected in final stats if needed
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
                    if (!$collectedData['includeCoreFiles'] && $this->isCoreFile($filePath)) {
                        continue;
                    }

                    // Check if this file matches the requested ISO code
                    $fileIso = $this->getIsoFromFilePath($filePath);
                    $expectedIso = $collectedData['snippetSet']['iso'];
                    
                    // Match ISO codes - support both full (de-DE) and short (de) formats
                    // Core files use short format (de), custom plugins use full format (de-DE)
                    if (!$this->isIsoMatch($fileIso, $expectedIso)) {
                        continue;
                    }

                    // Convert to clean relative path
                    // First resolve to absolute real path to handle symlinks and .. paths
                    $realPath = realpath($filePath);
                    $realProjectDir = realpath($collectedData['projectDir']);
                    
                    // Remove project directory and normalize
                    $relativePath = str_replace($realProjectDir, '', $realPath);
                    $relativePath = str_replace('\\', '/', $relativePath); // Convert Windows paths
                    // Ensure path starts with / for consistency
                    if (!str_starts_with($relativePath, '/')) {
                        $relativePath = '/' . $relativePath;
                    }
                    
                    // Determine if this is a core file or custom file
                    $isCoreFile = $this->isCoreFile($relativePath);

                    // If only collecting paths, skip reading file content
                    if ($collectedData['onlyCollectPath']) {
                        // Add file with only path information
                        $collectedData['files'][] = [
                            'fileName' => $fileName,
                            'filePath' => $relativePath,
                            'isCoreFile' => $isCoreFile
                        ];
                        
                        $collectedData['stats']['totalFiles']++;
                        
                        // Track core vs custom files
                        if ($isCoreFile) {
                            $collectedData['stats']['coreFiles']++;
                        } else {
                            $collectedData['stats']['customFiles']++;
                        }
                        
                        continue; // Skip to next file
                    }

                    // Full collection mode: read and parse file content
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
                    
                    // Add file with all its snippets
                    $collectedData['files'][] = [
                        'fileName' => $fileName,
                        'filePath' => $relativePath,
                        'snippets' => $flatSnippets,
                        'isCoreFile' => $isCoreFile
                    ];
                    
                    $collectedData['stats']['totalFiles']++;
                    $collectedData['stats']['totalSnippets'] += count($flatSnippets);
                    
                    // Track core vs custom files
                    if ($isCoreFile) {
                        $collectedData['stats']['coreFiles']++;
                    } else {
                        $collectedData['stats']['customFiles']++;
                    }

                } catch (\Exception $e) {
                    $collectedData['stats']['errorFiles']++;
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
     * Determine if a file path represents a core file (vendor/shopware or SwagLanguagePack)
     * 
     * @param string $filePath The file path to check
     * @return bool True if this is a core file, false if it's a custom plugin file
     */
    private function isCoreFile(string $filePath): bool
    {
        // Core files are defined as:
        // 1. Files in /vendor/shopware/ (Shopware core snippets)
        // 2. Files in /custom/plugins/SwagLanguagePack/ (Official language pack)
        return strpos($filePath, '/vendor/shopware/') !== false || 
               strpos($filePath, '/custom/plugins/SwagLanguagePack/') !== false;
    }

    /**
     * Check if the file ISO matches the expected ISO
     * Supports both full format (de-DE) and short format (de)
     * 
     * @param string|null $fileIso ISO extracted from filename
     * @param string $expectedIso Expected ISO from snippet set
     * @return bool True if they match
     */
    private function isIsoMatch(?string $fileIso, string $expectedIso): bool
    {
        if ($fileIso === null) {
            return false;
        }
        
        // Exact match (e.g., de-DE === de-DE)
        if ($fileIso === $expectedIso) {
            return true;
        }
        
        // Short format match (e.g., "de" matches "de-DE")
        // Core Shopware files use short format like "de", while plugins use "de-DE"
        $expectedShort = substr($expectedIso, 0, 2); // Get first 2 chars (de from de-DE)
        if ($fileIso === $expectedShort) {
            return true;
        }
        
        return false;
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

