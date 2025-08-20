<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\Translation\TranslatorInterface;
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
            KernelEvents::RESPONSE => 'onKernelResponse'
        ];
    }

        public function onKernelResponse(ResponseEvent $event): void
    {
        try {
            $request = $event->getRequest();
            $response = $event->getResponse();
            
            // Debug: Log all admin API calls to see which ones handle extensions
            $route = $request->attributes->get('_route');        
            // Check if this is an extension API call
            if (!$route || (strpos($route, 'extension') === false && strpos($request->getRequestUri(), 'extension') === false)) {
                 $this->writeLog("Route not extension: " . $route, __LINE__);
                return;
            }
            
            // Check if response contains JSON
            $contentType = $response->headers->get('content-type');
            if (!$contentType || strpos($contentType, 'application/json') === false) {
                return;
            }
            
            $content = $response->getContent();
            $data = json_decode($content, true);
            
            if (!$data) {
                return;
            }
            
            
            // Get version check data (cached for 24 hours)
            $versionData = $this->getVersionCheckData();
            $this->writeLog("Version data: " . json_encode($versionData, JSON_PRETTY_PRINT), __LINE__);
            if (!$versionData) {
                return;
            }

            $data_changed = false;
            if (is_array($data)) {
                foreach ($data as &$extension) {
               
                    //file_put_contents($debugFile, "Extension: " . json_encode($extension, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);
                    if (isset($extension['name']) && strpos($extension['name'], 'Reqser') !== false) {
                        if ($extension['name'] === 'ReqserPlugin' 
                        && isset($extension['version'])
                        && isset($versionData['plugin_version'])
                        && isset($versionData['plugin_download_url'])
                        ) {
                            $this->writeLog("Extension version: " . $extension['version'], __LINE__);
                            if ($this->versionService->updateIsNecessary($versionData['plugin_version'], $extension['version']) 
                                && (!isset($extension['updateAvailable']) || $extension['updateAvailable'] !== true)){
                                $this->writeLog("Update is necessary", __LINE__);

                                $extension['updateAvailable'] = true; 
                                $extension['latestVersion'] = $versionData['plugin_version']; 
                                $updateMessage = $this->translator->trans('reqser-plugin.update.clickTwiceToUpdate', ['%version%' => $versionData['plugin_version']]);
                                $extension['label'] .= ' (' . $updateMessage . ')';

                                $this->writeLog("All Extension data after update: " . json_encode($extension, JSON_PRETTY_PRINT), __LINE__);
                                
                                $data_changed = true;
                            } 
                        } elseif ($extension['name'] === 'ReqserApp') {
                            //Todo Add APP update
                        }
                    }
                    
                }
            }
            if ($data_changed) {
                $response->setContent(json_encode($data));
                $event->setResponse($response);
            }
        } catch (\Throwable $e) {
            // Silently fail - never break the application
        }
        
        return;
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
