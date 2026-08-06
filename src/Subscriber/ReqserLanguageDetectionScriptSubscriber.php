<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Appends the language detection script to the end of the storefront home page body.
 */
class ReqserLanguageDetectionScriptSubscriber implements EventSubscriberInterface
{
    private const HOME_ROUTE = 'frontend.home.page';
    private const DETECTION_ROUTE = 'frontend.reqser.language_detection.check';
    private const SCRIPT_ATTRIBUTE = 'data-reqser-language-detection';
    private const BODY_CLOSING_TAG = '</body>';
    private const ENDPOINT_PLACEHOLDER = '__REQSER_DETECTION_ENDPOINT__';

    private RouterInterface $router;

    /**
     * @param RouterInterface $router
     */
    public function __construct(RouterInterface $router)
    {
        $this->router = $router;
    }

    public static function getSubscribedEvents(): array
    {
        // Runs late so the HTML is final; injecting a tag is the last thing done to it.
        return [
            KernelEvents::RESPONSE => [['injectDetectionScript', -1000]],
        ];
    }

    /**
     * @param ResponseEvent $event
     * @return void
     */
    public function injectDetectionScript(ResponseEvent $event): void
    {
        try {
            if (!$event->isMainRequest()) {
                return;
            }

            $request = $event->getRequest();
            if ($request->attributes->get('_route') !== self::HOME_ROUTE || $request->isXmlHttpRequest()) {
                return;
            }

            $response = $event->getResponse();
            if ($response->getStatusCode() !== 200) {
                return;
            }

            if (!str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                return;
            }

            $content = $response->getContent();
            if (!is_string($content) || str_contains($content, self::SCRIPT_ATTRIBUTE)) {
                return;
            }

            $position = strripos($content, self::BODY_CLOSING_TAG);
            if ($position === false) {
                return;
            }

            $response->setContent(
                substr($content, 0, $position) . $this->buildScriptTag() . substr($content, $position)
            );

            if ($response->headers->has('Content-Length')) {
                $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * @return string
     */
    private function buildScriptTag(): string
    {
        $endpoint = json_encode(
            $this->router->generate(self::DETECTION_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_PATH),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return '<script ' . self::SCRIPT_ATTRIBUTE . '="1">'
            . str_replace(self::ENDPOINT_PLACEHOLDER, (string) $endpoint, $this->detectionScript())
            . '</script>';
    }

    /**
     * @return string
     */
    private function detectionScript(): string
    {
        return <<<'JS'
(function () {
    'use strict';

    if (window.reqserLanguageDetectionStarted) {
        return;
    }
    window.reqserLanguageDetectionStarted = true;

    var endpoint = __REQSER_DETECTION_ENDPOINT__;
    var params = new URLSearchParams(window.location.search);
    var debugMode = params.get('reqserdebugmode') === 'true';
    var blockRedirect = params.get('reqsernoredirectmode') === 'true';
    var started = false;

    function debug() {
        if (debugMode) {
            console.log.apply(console, arguments);
        }
    }

    debug('Reqser Language Detection: armed - readyState', document.readyState);

    if (blockRedirect) {
        console.log('Reqser Language Detection: reqsernoredirectmode is on, redirects are blocked');
    }

    function checkUrl() {
        return endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + '_cb=' + Date.now();
    }

    function requestHeaders() {
        return {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache'
        };
    }

    function applyDecision(data) {
        if (!data || !data.success || !data.shouldRedirect || !data.redirectUrl) {
            debug('Reqser Language Detection: no redirect - reason', data ? data.reason : 'no response');
            return;
        }

        if (blockRedirect) {
            console.log('Reqser Language Detection: REDIRECT BLOCKED, would have gone to', data.redirectUrl);
            console.log('Reqser Language Detection: decision', data);
            return;
        }

        debug('Reqser Language Detection: redirecting to', data.redirectUrl);
        window.location.replace(data.redirectUrl);
    }

    function requestWithFetch() {
        return fetch(checkUrl(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: requestHeaders()
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('status ' + response.status);
            }
            return response.json();
        }).then(applyDecision);
    }

    function requestWithXhr() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', checkUrl(), true);

        var headers = requestHeaders();
        Object.keys(headers).forEach(function (name) {
            xhr.setRequestHeader(name, headers[name]);
        });

        xhr.onload = function () {
            if (xhr.status !== 200) {
                debug('Reqser Language Detection: request failed with status', xhr.status);
                return;
            }
            try {
                applyDecision(JSON.parse(xhr.responseText));
            } catch (error) {
                debug('Reqser Language Detection: response was not JSON', error);
            }
        };

        xhr.onerror = function () {
            debug('Reqser Language Detection: request failed');
        };

        xhr.send();
    }

    function startCheck() {
        if (started) {
            return;
        }
        started = true;

        debug('Reqser Language Detection: checking', endpoint, '- readyState', document.readyState);

        try {
            if (typeof window.fetch === 'function') {
                requestWithFetch().catch(function (error) {
                    debug('Reqser Language Detection: fetch failed, retrying with XMLHttpRequest', error);
                    requestWithXhr();
                });
            } else {
                requestWithXhr();
            }
        } catch (error) {
            debug('Reqser Language Detection: check could not start', error);
        }
    }

    // The request must not compete with the page's own resources, so it waits for
    // the load event and then yields one task, keeping every page-speed metric free
    // of this feature. The timer is the safety net for a page whose load event is
    // held back by a stalled third-party resource -- without it a hanging asset
    // would mean no redirect at all.
    function scheduleCheck() {
        setTimeout(startCheck, 0);
    }

    if (document.readyState === 'complete') {
        scheduleCheck();
    } else {
        window.addEventListener('load', scheduleCheck);
        setTimeout(startCheck, 5000);
    }
})();
JS;
    }
}
