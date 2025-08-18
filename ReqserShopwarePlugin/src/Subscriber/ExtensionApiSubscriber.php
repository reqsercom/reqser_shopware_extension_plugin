<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpClient\HttpClient;

class ExtensionApiSubscriber implements EventSubscriberInterface
{
    private FilesystemAdapter $cache;
    private string $shopwareVersion;

    public function __construct(string $shopwareVersion)
    {
        $this->cache = new FilesystemAdapter('reqser_extension_api');
        $this->shopwareVersion = $shopwareVersion;
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
            
            // Get version check data (cached for 24 hours)
            $versionData = $this->getVersionCheckData();
     
            $data_changed = false;
            if (is_array($data)) {
                foreach ($data as &$extension) {
                    if (isset($extension['name']) && $extension['name'] === 'ReqserPlugin') {
                        if ($this->updateIsNecessary($versionData['plugin_version'], $extension['currentVersion'])){
                            $extension['updateAvailable'] = true; //$this->updateAvailable($versionData['plugin_version'], $extension['currentVersion']);
                            $extension['latestVersion'] = $versionData['plugin_version']; //$versionData['plugin_version'];
                            
                            // Add update source information
                            $extension['updateSource'] = 'reqser'; // Custom source identifier
                            $extension['downloadUrl'] = $versionData['plugin_download_url'];
                            $extension['changelog'] = [
                                'en-GB' => 'New features and improvements in version 2.0.0',
                                'de-DE' => 'Neue Funktionen und Verbesserungen in Version 2.0.0'
                            ];
                            $extension['releaseDate'] = date('Y-m-d');
                            $extension['compatible'] = true;
                            $extension['verified'] = true;
                            
                            $data_changed = true;
                        }
                        
                    } elseif (isset($extension['name']) && $extension['name'] === 'ReqserApp') {
                        /*$extension['updateAvailable'] = $this->updateIsNecessary($versionData['app_version'], $extension['currentVersion']);
                        $extension['latestVersion'] = $versionData['app_version'];
                        $data_changed = true;*/
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

    private function getVersionCheckData(): array
    {
        // Check cache for HTTP response (cached for 1 day)
        $httpCacheKey = 'reqser_version_check_http_response';
        $httpCached = $this->cache->getItem($httpCacheKey);
        
        $content = null;
        $statusCode = null;
        $cached_at = null;
        
        if ($httpCached->isHit()) {
            // Use cached response
            $cachedData = $httpCached->get();
            $content = $cachedData['content'];
            $statusCode = $cachedData['status_code'];
            $cached_at = $cachedData['cached_at'];
        } else {
            // Make fresh HTTP request
            try {
                set_time_limit(10); // Max 10 seconds for external call
                $shopwareVersion = $this->shopwareVersion;
                $client = HttpClient::create();
                $response = $client->request('GET', 'https://reqser.com/app/shopware/versioncheck/'.$shopwareVersion);
                
                $content = $response->getContent();
                $statusCode = $response->getStatusCode();
                $cached_at = date('Y-m-d H:i:s');
                
                // Cache the HTTP response for 1 day (86400 seconds)
                $httpCached->set([
                    'content' => $content,
                    'status_code' => $statusCode,
                    'cached_at' => $cached_at
                ]);
                $httpCached->expiresAfter(86400); // 24 hours
                $this->cache->save($httpCached);
                
            } catch (\Throwable $e) {
                // Fallback to cached data or defaults
                return ['updateAvailable' => false];
            }
        }
        
        // Parse response and return structured data
        if ($statusCode === 200 && $content) {
            $responseData = json_decode($content, true);
            if ($responseData && isset($responseData['content'])) {
                return ['content' => $responseData['content']];
            }
        }
        
        // Default fallback
        return ['updateAvailable' => false];
    }

    private function updateIsNecessary(string $latestVersion, string $currentVersion): bool
    {
        //Testing
        return true;
        try {
            // Parse version numbers
            $latest = $this->parseVersion($latestVersion);
            $current = $this->parseVersion($currentVersion);
            
            // Compare major version
            if ($latest['major'] > $current['major']) {
                return true;
            }
            
            // If major versions are equal, compare minor version
            if ($latest['major'] === $current['major'] && $latest['minor'] > $current['minor']) {
                return true;
            }
            
            // If major and minor are equal, we don't consider patch updates as requiring update
            // (patch updates are not considered "updateAvailable" for this use case)
            return false;
        } catch (\Throwable $e) {
            // If version comparison fails, assume no update available
            return false;
        }
    }

    private function parseVersion(string $version): array
    {
        // Remove any 'v' prefix and normalize
        $version = ltrim($version, 'v');
        
        // Split by dots and ensure we have at least 3 parts
        $parts = explode('.', $version);
        
        return [
            'major' => (int) ($parts[0] ?? 0),
            'minor' => (int) ($parts[1] ?? 0), 
            'patch' => (int) ($parts[2] ?? 0)
        ];
    }
}
