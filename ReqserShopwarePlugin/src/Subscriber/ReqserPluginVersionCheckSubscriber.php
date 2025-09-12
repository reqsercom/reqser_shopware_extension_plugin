<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Framework\Store\Event\InstalledExtensionsListingLoadedEvent;
use Reqser\Plugin\Service\ReqserVersionService;

class ReqserPluginVersionCheckSubscriber implements EventSubscriberInterface
{
    private FilesystemAdapter $cache;
    private ReqserVersionService $versionService;
    private TranslatorInterface $translator;
    private string $shopwareVersion;
    private string $debugFile;
    private bool $debugMode = false;

    public function __construct(ReqserVersionService $versionService, TranslatorInterface $translator)
    {
        $this->cache = new FilesystemAdapter('reqser_extension_api');
        $this->versionService = $versionService;
        $this->translator = $translator;
        $this->shopwareVersion = $versionService->getShopwareVersion();
        $this->debugFile = $this->versionService->getPluginDir() . '/debug_version_check.log';
    }

    public static function getSubscribedEvents(): array
    {
        return [
            InstalledExtensionsListingLoadedEvent::class => 'onExtensionsListingLoaded'
        ];
    }

    /**
     * Handle extension listing loaded event
     */
    public function onExtensionsListingLoaded(InstalledExtensionsListingLoadedEvent $event): void
    {
        try {
            $this->writeLog("=== EXTENSION LISTING LOADED EVENT ===", __LINE__);
            
            // Get version check data (cached for 24 hours)
            $versionData = $this->getVersionCheckData();
            $this->writeLog("Version data: " . json_encode($versionData, JSON_PRETTY_PRINT), __LINE__);
            
            if (!$versionData) {
                $this->writeLog("No version data available", __LINE__);
                return;
            }

            // Modify our plugin's extension data if present
            $extensions = $event->extensionCollection;
            $modified = false;
            
            foreach ($extensions as $extension) {
                if ($extension->getName() === 'ReqserPlugin') {
                    $this->writeLog("Found ReqserPlugin in extension collection", __LINE__);
                    
                    // Get current version from extension
                    $currentVersion = $extension->getVersion();
                    $this->writeLog("Extension version: " . $currentVersion, __LINE__);
                    
                    // Check if updateAvailable is already set to true - if so, don't modify
                    $vars = $extension->getVars();
                    $updateAlreadyAvailable = isset($vars['updateAvailable']) && $vars['updateAvailable'] === true;
                    
                    if (!$updateAlreadyAvailable) {
                        $this->writeLog("UpdateAvailable not set or false, checking if update is necessary", __LINE__);
                        
                        // Check if update is necessary
                        if ($this->versionService->updateIsNecessary($versionData['plugin_version'], $currentVersion)) {
                            $this->writeLog("Update is necessary", __LINE__);
                            
                            // Add update message to label
                            $updateMessage = $this->translator->trans('reqser-plugin.update.clickTwiceToUpdate', ['%version%' => $versionData['plugin_version']]);
                            $extension->setLabel($extension->getLabel() . ' (' . $updateMessage . ')');
                            $extension->setLatestVersion($versionData['plugin_version']);
                            
                            // Set updateAvailable using assign method (no direct setter exists)
                            $extension->assign(['updateAvailable' => true]);
                            $modified = true;
                            
                            $this->writeLog("Extension data modified with update info", __LINE__);
                        } else {
                            $this->writeLog("No update necessary", __LINE__);
                        }
                    } else {
                        $this->writeLog("UpdateAvailable already set to true, skipping modification", __LINE__);
                    }
                    break;
                }
            }
            
            if ($modified) {
                $this->writeLog("Extension collection was modified", __LINE__);
            }
            
        } catch (\Throwable $e) {
            $this->writeLog("Error in onExtensionsListingLoaded: " . $e->getMessage(), __LINE__);
        }
    }



    private function getVersionCheckData(): ?array
    {
        // Check cache for HTTP response (cached for 1 day)
        $httpCacheKey = 'reqser_versioncheck_http_request';
        $httpCached = $this->cache->getItem($httpCacheKey);
        
        $content = null;
        $statusCode = null;
        
        if ($httpCached->isHit()) {
            // Use cached response
            $cachedData = $httpCached->get();
            $content = $cachedData['content'];
            $statusCode = $cachedData['status_code'];
            $this->writeLog("Cached successfully retrieved data: " . json_encode($cachedData, JSON_PRETTY_PRINT), __LINE__);
        } else {
            // Make fresh HTTP request using shared service
            try {
                set_time_limit(10); // Max 10 seconds for external call
                $result = $this->versionService->getVersionData();
                
                if ($result) {
                    $content = json_encode($result);
                    $statusCode = 200;
                } else {
                    $content = null;
                    $statusCode = 500;
                }
                
                // Cache the HTTP response for 1 day (86400 seconds)
                $httpCached->set([
                    'content' => $content,
                    'status_code' => $statusCode,
                    'cached_at' => date('Y-m-d H:i:s')
                ]);
                $httpCached->expiresAfter(86400);
                $this->cache->save($httpCached);
                
            } catch (\Throwable $e) {
                return null;
            }
        }
        
        try {
            // Parse response and return structured data
            if ($statusCode === 200 && $content) {
                $responseData = json_decode($content, true);
                if ($responseData) {
                    return $responseData;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        
        return null;
    }

    private function writeLog(string $message, $line = null): void
    {
        if (!$this->debugMode) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $lineInfo = $line ? " [Line:$line]" : "";
        file_put_contents($this->debugFile, "[$timestamp]$lineInfo $message\n", FILE_APPEND | LOCK_EX);
    }
}