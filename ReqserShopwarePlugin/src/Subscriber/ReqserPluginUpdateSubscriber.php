<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\Plugin\Event\PluginPreUpdateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUpdateEvent;
use Symfony\Component\HttpClient\HttpClient;
use Reqser\Plugin\Service\ReqserVersionService;

class ReqserPluginUpdateSubscriber implements EventSubscriberInterface
{
    private ReqserVersionService $versionService;
    private string $debugFile;

    public function __construct(ReqserVersionService $versionService)
    {
        $this->versionService = $versionService;
        $this->debugFile = __DIR__ . '/../../debug_plugin_update.log';
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
        
        $this->writeLog("PluginPreUpdateEvent triggered for: " . $plugin->getName());
        
        // Only handle our plugin
        if ($plugin->getName() !== 'ReqserPlugin') {
            $this->writeLog("Not our plugin, skipping custom update");
            return;
        }

        try {
            $this->writeLog("ReqserPlugin: Starting custom update process");
            
            // Get current plugin version and check for updates
            $currentVersion = $this->versionService->getCurrentPluginVersion();
            $versionData = $this->versionService->getVersionData();
            
            $this->writeLog("ReqserPlugin: Version data received: " . json_encode($versionData));
            
            if (!$versionData || !isset($versionData['plugin_download_url'])) {
                $this->writeLog("ReqserPlugin: No update data available");
                return;
            }

            // Check if update is actually necessary before downloading
            if (!$this->versionService->updateIsNecessary($versionData['plugin_version'], $currentVersion)) {
                $this->writeLog("ReqserPlugin: No update necessary - current: $currentVersion, latest: {$versionData['plugin_version']}");
                return;
            }

            $this->writeLog("ReqserPlugin: Update is necessary - current: $currentVersion, latest: {$versionData['plugin_version']}");

            // Download and extract the new version
            $success = $this->downloadAndExtractUpdate($versionData);
            
            if ($success) {
                $this->writeLog("ReqserPlugin: Successfully downloaded and extracted update");
                
                // Set the upgrade version for Shopware's update process
                try {
                    $newVersion = $versionData['plugin_version'];
                    $this->writeLog("ReqserPlugin: Setting upgrade version to: $newVersion");
                    
                    // Use the public setter to set the upgrade version
                    $plugin->setUpgradeVersion($newVersion);
                    $this->writeLog("ReqserPlugin: Successfully set upgradeVersion to: $newVersion");
                } catch (\Throwable $e) {
                    $this->writeLog("ReqserPlugin: Failed to set upgrade version: " . $e->getMessage());
                }
            } else {
                $this->writeLog("ReqserPlugin: Failed to download update");
            }
            
        } catch (\Throwable $e) {
            $this->writeLog("ReqserPlugin: Update failed: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
        }
    }

    public function onPluginPostUpdate(PluginPostUpdateEvent $event): void
    {
        $plugin = $event->getPlugin();
        
        // Only handle our plugin
        if ($plugin->getName() !== 'ReqserPlugin') {
            return;
        }

        $this->writeLog("ReqserPlugin: Update completed successfully");
        
        // Ensure the plugin version in database matches the updated composer.json
        try {
            $currentVersion = $this->versionService->getCurrentPluginVersion();
            $this->writeLog("ReqserPlugin: Post-update - Current version from composer.json: $currentVersion");
            $this->writeLog("ReqserPlugin: Post-update - Plugin entity version: " . $plugin->getVersion());
            
            // If versions don't match, the database wasn't updated properly
            if ($plugin->getVersion() !== $currentVersion) {
                $this->writeLog("ReqserPlugin: Version mismatch detected - database may need manual refresh");
            }
        } catch (\Throwable $e) {
            $this->writeLog("ReqserPlugin: Post-update version check failed: " . $e->getMessage());
        }
    }

    private function downloadAndExtractUpdate(array $versionData): bool
    {
        try {
            $downloadUrl = $versionData['plugin_download_url'];
            $pluginDir = __DIR__ . '/../../';
            $tempFile = sys_get_temp_dir() . '/reqser_plugin_update.zip';

            $this->writeLog("ReqserPlugin: Download details - URL: $downloadUrl, Plugin Dir: $pluginDir, Temp File: $tempFile");

            // Download the ZIP file
            $client = HttpClient::create();
            $response = $client->request('GET', $downloadUrl);
            
            if ($response->getStatusCode() !== 200) {
                $this->writeLog("ReqserPlugin: Download failed with status: " . $response->getStatusCode());
                return false;
            }

            // Save to temp file
            file_put_contents($tempFile, $response->getContent());

            $this->writeLog("ReqserPlugin: Downloaded ZIP to: " . $tempFile);

            // Extract ZIP to plugin directory
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === true) {
                // Debug: Check ZIP contents
                $fileList = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $fileList[] = $zip->getNameIndex($i);
                }
                $this->writeLog("ReqserPlugin: ZIP contains " . $zip->numFiles . " files: " . implode(', ', array_slice($fileList, 0, 10)) . (count($fileList) > 10 ? '...' : ''));
                
                // Check if ZIP has a root folder (like ReqserShopwarePlugin/)
                $firstFile = $zip->getNameIndex(0);
                $hasRootFolder = strpos($firstFile, '/') !== false && substr_count($firstFile, '/') > 0;
                
                if ($hasRootFolder) {
                    $this->writeLog("ReqserPlugin: ZIP has root folder structure, extracting to temp dir first");
                    
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
                        $this->writeLog("ReqserPlugin: Found plugin folder: $pluginFolder, copying to $pluginDir");
                        
                        // Copy contents from the subfolder to plugin directory
                        $sourceDir = $tempExtractDir . '/' . $pluginFolder;
                        $this->recursiveCopy($sourceDir, $pluginDir);
                        
                        // Clean up
                        $this->recursiveDelete($tempExtractDir);
                    } else {
                        $this->writeLog("ReqserPlugin: Could not find plugin folder in extracted ZIP");
                        return false;
                    }
                } else {
                    $this->writeLog("ReqserPlugin: ZIP has flat structure, extracting directly");
                    // Extract new files directly (flat structure)
                    $zip->extractTo($pluginDir);
                    $zip->close();
                }
                
                // Clean up temp file
                unlink($tempFile);
                
                $this->writeLog("ReqserPlugin: Successfully extracted update");
                return true;
            } else {
                $this->writeLog("ReqserPlugin: Failed to open ZIP file");
                return false;
            }

        } catch (\Throwable $e) {
            $this->writeLog("ReqserPlugin: Download/extract failed: " . $e->getMessage());
            return false;
        }
    }

    private function writeLog(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->debugFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
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
}