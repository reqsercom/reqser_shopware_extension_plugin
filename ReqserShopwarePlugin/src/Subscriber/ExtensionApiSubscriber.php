<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpClient\HttpClient;
use Reqser\Plugin\Service\ReqserVersionService;

class ExtensionApiSubscriber implements EventSubscriberInterface
{
    private FilesystemAdapter $cache;
    private ReqserVersionService $versionService;
    private string $shopwareVersion;

    public function __construct(ReqserVersionService $versionService)
    {
        $this->cache = new FilesystemAdapter('reqser_extension_api');
        $this->versionService = $versionService;
        $this->shopwareVersion = $versionService->getShopwareVersion();
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
            $debugFile = __DIR__ . '/../../debug_extension.log';
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($debugFile, "\n=== DEBUG EXTENSION API [{$timestamp}] ===\n", FILE_APPEND | LOCK_EX);
            
            // Get version check data (cached for 24 hours)
            $versionData = $this->getVersionCheckData();
            file_put_contents($debugFile, "Version data: " . json_encode($versionData, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);
            if (!$versionData) {
                return;
            }

            $data_changed = false;
            if (is_array($data)) {
                foreach ($data as &$extension) {
               
                    //file_put_contents($debugFile, "Extension: " . json_encode($extension, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);
                    if (isset($extension['name']) && strpos($extension['name'], 'Reqser') !== false) {
                        //file_put_contents($debugFile, "Extension name: " . $extension['name'] . "\n", FILE_APPEND | LOCK_EX);
                        if ($extension['name'] === 'ReqserPlugin' 
                        && isset($extension['version'])
                        && isset($versionData['plugin_version'])
                        && isset($versionData['plugin_download_url'])
                        ) {
                            file_put_contents($debugFile, "Extension version: " . $extension['version'] . "\n", FILE_APPEND | LOCK_EX);
                            if ($this->versionService->updateIsNecessary($versionData['plugin_version'], $extension['version'])){
                                file_put_contents($debugFile, "Update is necessary\n", FILE_APPEND | LOCK_EX);
                                file_put_contents($debugFile, "All Extension data before update: " . json_encode($extension, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);
                                $extension['updateAvailable'] = true; 
                                $extension['latestVersion'] = $versionData['plugin_version']; 
                                
                                $extension['changelog'] = [
                                    'en-GB' => 'Details available at customer support of Reqser.com',
                                    'de-DE' => 'Details sind beim Kundensupport von Reqser.com erhältlich.'
                                ];
                                $extension['releaseDate'] = date('Y-m-d');
                                $extension['compatible'] = true;
                                $extension['verified'] = true;

                                file_put_contents($debugFile, "All Extension data after update: " . json_encode($extension, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);
                                
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
                $httpCached->expiresAfter(1); // 86400, 24 hours testing use 1
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

}
