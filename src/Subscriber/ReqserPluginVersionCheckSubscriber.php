<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Framework\Store\Event\InstalledExtensionsListingLoadedEvent;
use Reqser\Plugin\Service\ReqserVersionService;
use Reqser\Plugin\Service\ReqserNotificationService;

class ReqserPluginVersionCheckSubscriber implements EventSubscriberInterface
{
    private FilesystemAdapter $cache;
    private ReqserVersionService $versionService;
    private TranslatorInterface $translator;
    private ReqserNotificationService $notificationService;
    private string $shopwareVersion;
    private string $debugFile;
    private bool $debugMode = false;

    public function __construct(ReqserVersionService $versionService, TranslatorInterface $translator, ReqserNotificationService $notificationService)
    {
        $this->cache = new FilesystemAdapter('reqser_extension_api');
        $this->versionService = $versionService;
        $this->translator = $translator;
        $this->notificationService = $notificationService;
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
                    
                    // Check if update is necessary
                    if ($this->versionService->updateIsNecessary($versionData['plugin_version'], $currentVersion)) {
                        $this->writeLog("Update is necessary", __LINE__);
                        
                        // Add update message without URL (clean display)
                        $downloadUrl = $versionData['plugin_download_url'] ?? null;
                        $updateText = $this->translator->trans('reqser-plugin.update.updateAvailable');
                        $extension->setLabel(str_replace("AI Extension", "", $extension->getLabel()) . ' (' . $updateText . ')');
                        if ($downloadUrl) {
                            // Send user-specific notification - try to get user from admin context
                            $notificationMessage = 'ReqserPlugin Update Available' . "\n\n" . 'Download Link: ' . $downloadUrl;
                            
                            $this->notificationService->sendAdminNotification($notificationMessage, 'info', ['system.plugin_maintain']);
                        } else {
                            // Fallback if no download URL - just show text
                            $extension->setLabel($extension->getLabel() . ' (' . $updateText . ')');
                        }
                        
                        $modified = true;
                        
                        $this->writeLog("Extension data modified with download link", __LINE__);
                    } else {
                        $this->writeLog("No update necessary", __LINE__);
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