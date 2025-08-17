<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpClient\HttpClient;

class ReqserVersionCheckSubscriber implements EventSubscriberInterface
{
    private FilesystemAdapter $cache;

    public function __construct()
    {
        $this->cache = new FilesystemAdapter('reqser_version_check');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController'
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        // Only process main requests, not sub-requests
        if (!$event->isMainRequest() || !$route || strpos($route, 'administration.') !== 0) {
            return;
        }

        // Smart caching: Only run once per minute to avoid performance issues
        $cacheKey = 'reqser_version_check_executed';
        $cached = $this->cache->getItem($cacheKey);

        // Set timeout protection - max 1 testing 10 second execution
        set_time_limit(10);
        
        try {
            // Check cache for HTTP response (cached for 1 day)
            $httpCacheKey = 'reqser_version_check_http_response';
            $httpCached = $this->cache->getItem($httpCacheKey);
            
            $content = null;
            $statusCode = null;
            
            if ($httpCached->isHit()) {
                // Use cached response
                $cachedData = $httpCached->get();
                $content = $cachedData['content'];
                $statusCode = $cachedData['status_code'];
                $cached_at = $cachedData['cached_at'];
            } else {
                // Make fresh HTTP request
                $client = HttpClient::create();
                $response = $client->request('GET', 'https://reqser.com/app/shopware/check_version');
                
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
            }
            
            if ($statusCode === 200) {
                // Your logic here - this always executes
                dd("call to reqser done", $content, $statusCode, $cached_at);
            }

        } catch (\Throwable $e) {
            //Nothing for now, add webhook call later
        }
    }
}
