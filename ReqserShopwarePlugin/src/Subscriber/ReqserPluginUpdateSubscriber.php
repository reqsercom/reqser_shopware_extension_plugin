<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\Plugin\Event\PluginPreUpdateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUpdateEvent;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\Translation\TranslatorInterface;
use Reqser\Plugin\Service\ReqserVersionService;
use Reqser\Plugin\Service\ReqserNotificationService;
use Psr\Log\LoggerInterface;

class ReqserPluginUpdateSubscriber implements EventSubscriberInterface
{
    private ReqserVersionService $versionService;
    private ReqserNotificationService $notificationService;
    private TranslatorInterface $translator;
    private LoggerInterface $logger;
    private bool $debugMode = false;
    private static bool $updateInProgress = false;

    public function __construct(
        ReqserVersionService $versionService,
        ReqserNotificationService $notificationService,
        TranslatorInterface $translator,
        LoggerInterface $logger
    ) {
        $this->versionService = $versionService;
        $this->notificationService = $notificationService;
        $this->translator = $translator;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginPreUpdateEvent::class => 'onPluginPreUpdate',
            PluginPostUpdateEvent::class => 'onPluginPostUpdate'
        ];
    }

    public function onPluginPreUpdate(PluginPreUpdateEvent $event): void
    {
        $plugin = $event->getPlugin();
        
        $this->writeLog("PluginPreUpdateEvent triggered for: " . $plugin->getName(), __LINE__);
        
        // Only handle our plugin
        if ($plugin->getName() !== 'ReqserPlugin') {
            $this->writeLog("Not our plugin, skipping custom update", __LINE__);
            return;
        }

        // Prevent multiple simultaneous updates
        if (self::$updateInProgress) {
            $this->writeLog("Update already in progress, skipping", __LINE__);
            return;
        }

        try {
            // Set update in progress flag
            self::$updateInProgress = true;
            
            $this->writeLog("Starting custom update process", __LINE__);
            
            // Set the plugin instance in the version service for proper path resolution
            $this->versionService->setPlugin($plugin);
            
            // Get current plugin version and check for updates
            $currentVersion = $this->versionService->getCurrentPluginVersion();
            if (!isset($currentVersion)) {
                $this->writeLog("No current version found, skipping update", __LINE__);
                return;
            }
            $versionData = $this->versionService->getVersionData();
            
            $this->writeLog("Version data received: " . json_encode($versionData), __LINE__);
            
            if (!$versionData || !isset($versionData['plugin_download_url']) || !isset($versionData['plugin_version'])) {
                $this->writeLog("No update data available or missing required fields", __LINE__);
                return;
            }

            $targetVersion = $versionData['plugin_version'];

            // Check if the target version is already installed (avoid unnecessary downloads)
            if ($currentVersion === $targetVersion) {
                $this->writeLog("Target version {$targetVersion} is already installed (current: {$currentVersion}), skipping download", __LINE__);
                return;
            }

            // Check if update is actually necessary before downloading
            if (!$this->versionService->updateIsNecessary($targetVersion, $currentVersion)) {
                $this->writeLog("No update necessary - current: $currentVersion, latest: {$targetVersion}", __LINE__);
                return;
            }

            $this->writeLog("Update is necessary - current: $currentVersion, latest: {$targetVersion}", __LINE__);

            // Create backup of current plugin folder
            $backupSuccess = $this->createPluginBackup();
            if (!$backupSuccess) {
                $this->writeLog("Failed to create backup, aborting update", __LINE__);
                return;
            }

            // Download and extract the new version
            $success = $this->downloadAndExtractUpdate($versionData);
            
            if ($success) {
                $this->writeLog("Successfully downloaded and extracted update", __LINE__);
                
                // Clean up backup folder after successful update
                $this->cleanupBackup();

                // Send translated message to user that download was successful and they need to click update again
                $message = $this->translator->trans('reqser-plugin.update.downloadSuccessful');
                $this->writeLog("Translated message: $message", __LINE__);
                
                // If translation failed (returns the key), use fallback English text
                if ($message === 'reqser-plugin.update.downloadSuccessful') {
                    $message = 'Download was successful. Please click update again to complete the process.';
                    $this->writeLog("Translation failed, using fallback message: $message", __LINE__);
                }
                
                $this->notificationService->sendAdminNotification(
                    $message, 
                    'ReqserPlugin Update',
                    'success'
                );
                
                // Set the upgrade version for Shopware's update process
                /*try {
                    $this->writeLog("Setting upgrade version to: $targetVersion", __LINE__);
                    
                    // Use the public setter to set the upgrade version
                    $plugin->setUpgradeVersion($targetVersion);
                    $this->writeLog("Successfully set upgradeVersion to: $targetVersion", __LINE__);
                } catch (\Throwable $e) {
                    $this->writeLog("Failed to set upgrade version: " . $e->getMessage(), __LINE__);
                }*/
            } else {
                $this->writeLog("Failed to download update, attempting rollback", __LINE__);
                $this->rollbackFromBackup();
            }
            
        } catch (\Throwable $e) {
            $this->writeLog("Update failed: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(), __LINE__);
            $this->writeLog("Attempting rollback due to unexpected error", __LINE__);
            $this->rollbackFromBackup();
        } finally {
            // Always reset the flag
            self::$updateInProgress = false;
        }
    }

    public function onPluginPostUpdate(PluginPostUpdateEvent $event): void
    {
        $plugin = $event->getPlugin();
        
        // Only handle our plugin
        if ($plugin->getName() !== 'ReqserPlugin') {
            return;
        }

        $this->writeLog("Update completed successfully", __LINE__);
        
        // Ensure the plugin version in database matches the updated composer.json
        try {
            $currentVersion = $this->versionService->getCurrentPluginVersion();
            $this->writeLog("Post-update - Current version from composer.json: $currentVersion", __LINE__);
            $this->writeLog("Post-update - Plugin entity version: " . $plugin->getVersion(), __LINE__);
            
            // If versions don't match, the database wasn't updated properly
            if ($plugin->getVersion() !== $currentVersion) {
                $this->writeLog("Version mismatch detected - update process has to be triggered again", __LINE__);
            }
        } catch (\Throwable $e) {
            $this->writeLog("Post-update version check failed: " . $e->getMessage(), __LINE__);
        }
    }

    private function downloadAndExtractUpdate(array $versionData): bool
    {
        try {
            $downloadUrl = $versionData['plugin_download_url'];
            $pluginDir = $this->versionService->getPluginDir();
            $tempFile = sys_get_temp_dir() . '/reqser_plugin_update.zip';

            $this->writeLog("Download details - URL: $downloadUrl, Plugin Dir: $pluginDir, Temp File: $tempFile", __LINE__);

            // Clean up any existing temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            // Download the ZIP file
            $client = HttpClient::create();
            $response = $client->request('GET', $downloadUrl);
            
            if ($response->getStatusCode() !== 200) {
                $this->writeLog("Download failed with status: " . $response->getStatusCode(), __LINE__);
                return false;
            }

            // Save to temp file
            file_put_contents($tempFile, $response->getContent());

            $this->writeLog("Downloaded ZIP to: " . $tempFile, __LINE__);

            // Extract ZIP to plugin directory
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === true) {
                // Debug: Check ZIP contents
                $fileList = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $fileList[] = $zip->getNameIndex($i);
                }
                $this->writeLog("ZIP contains " . $zip->numFiles . " files: " . implode(', ', array_slice($fileList, 0, 10)) . (count($fileList) > 10 ? '...' : ''));
                
                // Check if ZIP has a root folder (like ReqserShopwarePlugin/)
                $firstFile = $zip->getNameIndex(0);
                $hasRootFolder = strpos($firstFile, '/') !== false && substr_count($firstFile, '/') > 0;
                
                if ($hasRootFolder) {
                    $this->writeLog("ZIP has root folder structure, extracting to temp dir first");
                    
                    // Extract to temp directory first
                    $tempExtractDir = sys_get_temp_dir() . '/reqser_extract_' . time();
                    mkdir($tempExtractDir);
                    $zip->extractTo($tempExtractDir);
                    $zip->close();
                    
                    // Find the plugin folder inside
                    $extracted = scandir($tempExtractDir);
                    $pluginFolder = null;
                    foreach ($extracted as $item) {
                        if ($item !== '.' && $item !== '..' && is_dir($tempExtractDir . '/' . $item)) {
                            $pluginFolder = $item;
                            break;
                        }
                    }
                    
                    if ($pluginFolder) {
                        $this->writeLog("Found plugin folder: $pluginFolder, replacing current plugin directory");
                        
                        // Remove current plugin directory completely
                        if (is_dir($pluginDir)) {
                            $this->recursiveDelete($pluginDir);
                        }
                        
                        // Copy the new plugin files
                        $sourceDir = $tempExtractDir . '/' . $pluginFolder;
                        $this->recursiveCopy($sourceDir, $pluginDir);
                        
                        // Clean up temp extraction directory
                        $this->recursiveDelete($tempExtractDir);
                    } else {
                        $this->writeLog("Could not find plugin folder in extracted ZIP");
                        // Clean up temp extraction directory
                        if (is_dir($tempExtractDir)) {
                            $this->recursiveDelete($tempExtractDir);
                        }
                        return false;
                    }
                } else {
                    $this->writeLog("ZIP has flat structure, replacing plugin directory");
                    
                    // Remove current plugin directory completely
                    if (is_dir($pluginDir)) {
                        $this->recursiveDelete($pluginDir);
                    }
                    
                    // Create new plugin directory
                    mkdir($pluginDir, 0755, true);
                    
                    // Extract new files directly (flat structure)
                    $zip->extractTo($pluginDir);
                    $zip->close();
                }
                
                // Clean up temp file
                unlink($tempFile);
                
                $this->writeLog("Successfully extracted update");
                return true;
            } else {
                $this->writeLog("Failed to open ZIP file");
                return false;
            }

        } catch (\Throwable $e) {
            $this->writeLog("Download/extract failed: " . $e->getMessage());
            
            // Clean up temp file if it exists
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }
            
            return false;
        }
    }

    private function writeLog(string $message, $line = null): void
    {
        if (!$this->debugMode) {
            return;
        }
        
        $lineInfo = $line ? " [Line:$line]" : "";
        $this->logger->info("ReqserPlugin Update: $message$lineInfo", ['file' => __FILE__, 'line' => __LINE__]);
    }

    private function recursiveCopy(string $source, string $dest): void
    {
        if (is_dir($source)) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
            
            $files = scandir($source);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->recursiveCopy("$source/$file", "$dest/$file");
                }
            }
        } else {
            copy($source, $dest);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->recursiveDelete("$dir/$file");
                }
            }
            rmdir($dir);
        } else {
            unlink($dir);
        }
    }

    private function createPluginBackup(): bool
    {
        try {
            $pluginDir = $this->versionService->getPluginDir();
            $backupDir = $this->versionService->getPluginsDir() . '/ReqserShopwarePlugin_backup';

            $this->writeLog("Creating backup from $pluginDir to $backupDir");

            // Remove existing backup if it exists
            if (is_dir($backupDir)) {
                $this->writeLog("Removing existing backup directory");
                $this->recursiveDelete($backupDir);
            }

            // Create backup by copying current plugin directory
            $this->recursiveCopy($pluginDir, $backupDir);

            $this->writeLog("Backup created successfully at $backupDir");
            return true;

        } catch (\Throwable $e) {
            $this->writeLog("Failed to create backup: " . $e->getMessage());
            return false;
        }
    }

    private function cleanupBackup(): void
    {
        try {
            $pluginDir = $this->versionService->getPluginDir();
            $backupDir = $this->versionService->getPluginsDir() . '/ReqserShopwarePlugin_backup';

            if (is_dir($backupDir)) {
                $this->writeLog("Cleaning up backup directory at $backupDir");
                $this->recursiveDelete($backupDir);
                $this->writeLog("Backup cleanup completed");
            } else {
                $this->writeLog("No backup directory found to clean up");
            }

        } catch (\Throwable $e) {
            $this->writeLog("Failed to cleanup backup: " . $e->getMessage());
        }
    }

    private function rollbackFromBackup(): void
    {
        try {
            $pluginDir = $this->versionService->getPluginDir();
            $backupDir = $this->versionService->getPluginsDir() . '/ReqserShopwarePlugin_backup';

            if (!is_dir($backupDir)) {
                $this->writeLog("No backup directory found for rollback");
                return;
            }

            $this->writeLog("Rolling back from backup at $backupDir");

            // Remove current (potentially corrupted) plugin directory
            if (is_dir($pluginDir)) {
                $this->recursiveDelete($pluginDir);
            }

            // Restore from backup
            $this->recursiveCopy($backupDir, $pluginDir);

            // Clean up backup after successful rollback
            $this->recursiveDelete($backupDir);

            $this->writeLog("Rollback completed successfully");

        } catch (\Throwable $e) {
            $this->writeLog("Failed to rollback from backup: " . $e->getMessage());
        }
    }
}