<?php declare(strict_types=1);

namespace Reqser\Plugin\Subscriber;

use Reqser\Plugin\Service\ReqserSalesAgentService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Appends the sales agent launcher and the host-side action bridge to the end of a storefront body.
 */
class ReqserSalesAgentWidgetSubscriber implements EventSubscriberInterface
{
    private const TOKEN_ROUTE = 'frontend.reqser.sales_agent.token';
    private const ADD_TO_CART_ROUTE = 'frontend.checkout.line-item.add';
    private const SCRIPT_ATTRIBUTE = 'data-reqser-sales-agent';
    private const BODY_CLOSING_TAG = '</body>';
    private const WIDGET_PATH = '/sales-agent/widget';

    private $salesAgentService;
    private RouterInterface $router;

    /**
     * @param ReqserSalesAgentService $salesAgentService
     * @param RouterInterface $router
     */
    public function __construct(ReqserSalesAgentService $salesAgentService, RouterInterface $router)
    {
        $this->salesAgentService = $salesAgentService;
        $this->router = $router;
    }

    public static function getSubscribedEvents(): array
    {
        // Runs late so the HTML is final; injecting a tag is the last thing done to it.
        return [
            KernelEvents::RESPONSE => [['injectWidget', -1000]],
        ];
    }

    /**
     * @param ResponseEvent $event
     * @return void
     */
    public function injectWidget(ResponseEvent $event): void
    {
        try {
            if (!$event->isMainRequest()) {
                return;
            }

            $request = $event->getRequest();
            if ($request->isXmlHttpRequest()) {
                return;
            }

            if (!str_starts_with((string) $request->attributes->get('_route'), 'frontend.')) {
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

            $config = $this->salesAgentService->getWidgetConfig($request);
            if ($config === null) {
                return;
            }

            $position = strripos($content, self::BODY_CLOSING_TAG);
            if ($position === false) {
                return;
            }

            $response->setContent(
                substr($content, 0, $position) . $this->buildScriptTag($config) . substr($content, $position)
            );

            if ($response->headers->has('Content-Length')) {
                $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * @param array $config
     * @return string
     */
    private function buildScriptTag(array $config): string
    {
        $replacements = [
            '__WIDGET_URL__' => $this->encode($config['widgetOrigin'] . self::WIDGET_PATH),
            '__WIDGET_ORIGIN__' => $this->encode($config['widgetOrigin']),
            '__TOKEN_URL__' => $this->encode(
                $this->router->generate(self::TOKEN_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ),
            '__ADD_TO_CART_URL__' => $this->encode(
                $this->router->generate(self::ADD_TO_CART_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ),
        ];

        return '<script ' . self::SCRIPT_ATTRIBUTE . '="1">'
            . strtr($this->bridgeScript(), $replacements)
            . '</script>';
    }

    /**
     * @param string $value
     * @return string
     */
    private function encode(string $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * @return string
     */
    private function bridgeScript(): string
    {
        return <<<'JS'
(function () {
    'use strict';

    if (window.__reqserSalesAgent) {
        return;
    }
    window.__reqserSalesAgent = true;

    var WIDGET_URL = __WIDGET_URL__;
    var WIDGET_ORIGIN = __WIDGET_ORIGIN__;
    var TOKEN_URL = __TOKEN_URL__;
    var ADD_TO_CART_URL = __ADD_TO_CART_URL__;
    var CHANNEL = 'reqser-sales-agent';

    var panel = null;
    var frame = null;
    var launcher = null;
    var isOpen = false;
    var framePending = false;

    function styled(tag, css) {
        var node = document.createElement(tag);
        node.style.cssText = css;
        return node;
    }

    // Only the launcher is built up front. The frame costs a token round trip
    // and a cross-origin document, so a shopper who never opens the agent pays
    // for neither.
    function build() {
        panel = styled('div',
            'position:fixed;bottom:96px;right:24px;z-index:2147483000;'
            + 'width:min(420px, calc(100vw - 32px));height:min(640px, calc(100vh - 140px));'
            + 'border-radius:16px;overflow:hidden;display:none;background:#fff;'
            + 'box-shadow:0 24px 60px rgba(15,23,42,.28);'
        );
        panel.setAttribute('data-reqser-sales-agent-panel', '1');

        launcher = styled('button',
            'position:fixed;bottom:24px;right:24px;z-index:2147483000;'
            + 'width:60px;height:60px;border:0;border-radius:50%;cursor:pointer;'
            + 'background:#0f172a;color:#fff;font-size:24px;line-height:1;'
            + 'box-shadow:0 12px 30px rgba(15,23,42,.35);'
        );
        launcher.type = 'button';
        launcher.setAttribute('aria-label', 'Sales Agent');
        launcher.textContent = '\u2726';
        launcher.addEventListener('click', function () {
            toggle(!isOpen);
        });

        document.body.appendChild(panel);
        document.body.appendChild(launcher);
    }

    function toggle(next) {
        isOpen = !!next;
        panel.style.display = isOpen ? 'block' : 'none';
        launcher.textContent = isOpen ? '\u00d7' : '\u2726';

        if (isOpen) {
            ensureFrame();
        }
    }

    // The token travels in the fragment so it stays out of the Referer header
    // and out of Reqser's own access log.
    function ensureFrame() {
        if (frame !== null || framePending) {
            return;
        }
        framePending = true;

        fetch(TOKEN_URL, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('token request failed with status ' + response.status);
            }
            return response.json();
        }).then(function (data) {
            if (!data || !data.success || !data.token) {
                throw new Error('token request returned no token');
            }

            frame = document.createElement('iframe');
            frame.title = 'Reqser Sales Agent';
            frame.style.cssText = 'width:100%;height:100%;border:0;display:block;';
            frame.src = WIDGET_URL
                + (WIDGET_URL.indexOf('?') === -1 ? '?' : '&')
                + 'shopOrigin=' + encodeURIComponent(window.location.origin)
                + '#token=' + encodeURIComponent(data.token);
            panel.appendChild(frame);
        }).catch(function () {
            framePending = false;
        });
    }

    // The launcher is the only part of the agent that lives on the shop page
    // instead of inside the frame, so it cannot pick up the widget's tokens
    // through CSS. The widget pushes the handful of values it needs once the
    // design has resolved. A value that is not a plain colour or a plain number
    // is ignored, so this stays an assignment of values and never of CSS text.
    function applyLauncher(style) {
        if (!launcher || !style || typeof style !== 'object') {
            return;
        }

        if (/^#[0-9a-f]{6}$/i.test(style.background)) {
            launcher.style.background = style.background;
        }
        if (/^#[0-9a-f]{6}$/i.test(style.color)) {
            launcher.style.color = style.color;
        }
        if (typeof style.radius === 'number' && style.radius >= 0) {
            // The launcher is a 60px square, so 30px already reads as a circle.
            launcher.style.borderRadius = Math.min(Math.round(style.radius), 30) + 'px';
        }
    }

    // The iframe is on a foreign origin, so every reply names that origin
    // explicitly instead of '*'. A wildcard would leak cart state to whatever
    // document happens to occupy the frame.
    function reply(type, payload, requestId) {
        if (!frame || !frame.contentWindow) {
            return;
        }

        var message = { channel: CHANNEL, type: type, requestId: requestId || null };
        Object.keys(payload || {}).forEach(function (key) {
            message[key] = payload[key];
        });

        frame.contentWindow.postMessage(message, WIDGET_ORIGIN);
    }

    function addToCart(msg) {
        var productId = typeof msg.productId === 'string' ? msg.productId : null;
        var quantity = parseInt(msg.quantity, 10);
        quantity = isFinite(quantity) && quantity > 0 ? quantity : 1;

        if (!productId) {
            reply('addToCartResult', { ok: false, error: 'missing productId' }, msg.requestId);
            return;
        }

        var body = new FormData();
        body.append('lineItems[' + productId + '][id]', productId);
        body.append('lineItems[' + productId + '][referencedId]', productId);
        body.append('lineItems[' + productId + '][type]', 'product');
        body.append('lineItems[' + productId + '][quantity]', String(quantity));
        body.append('lineItems[' + productId + '][stackable]', '1');
        body.append('lineItems[' + productId + '][removable]', '1');

        fetch(ADD_TO_CART_URL, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('add to cart failed with status ' + response.status);
            }

            refreshCartWidget();
            reply('addToCartResult', { ok: true, productId: productId, quantity: quantity }, msg.requestId);
        }).catch(function (error) {
            reply('addToCartResult', { ok: false, error: String(error.message || error) }, msg.requestId);
        });
    }

    // When the shopper is already on a product page, the delivery sentence is
    // rendered in this document. Lifting it beats deriving one: it is by
    // definition the text they can see. Themes that print no delivery block
    // simply yield null and the widget falls back to the crawled fields.
    function pageProduct() {
        if (!document.body.className.match(/\bis-ctl-product\b/)) {
            return null;
        }

        var name = document.querySelector('.product-detail-name, h1');
        var number = document.querySelector('.product-detail-ordernumber');
        var delivery = document.querySelector('.product-delivery-information, .delivery-information');

        function clean(node) {
            return node ? node.textContent.replace(/\s+/g, ' ').trim() : null;
        }

        return {
            name: clean(name),
            productNumber: clean(number),
            deliveryText: clean(delivery)
        };
    }

    // --- Storefront design probe -------------------------------------------
    //
    // The widget is cross-origin, so it can never read the shop's cascade. The
    // shop's own document is the only place where the theme is fully resolved:
    // custom properties, theme overrides and whatever the merchant pasted into
    // their custom CSS are all already applied here. So the probe runs in this
    // context and hands Reqser plain resolved values, never selectors or CSS.

    // Computed styles always come back as rgb()/rgba(), so this is the only
    // colour shape that needs parsing. An effectively transparent colour
    // resolves to null rather than to black, which is what the cascade means.
    function toHex(value) {
        if (typeof value !== 'string') {
            return null;
        }

        var parts = value.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,/\s]+([\d.]+))?\s*\)$/i);
        if (!parts) {
            return null;
        }
        if (parts[4] !== undefined && parseFloat(parts[4]) < 0.5) {
            return null;
        }

        function channel(raw) {
            var n = Math.max(0, Math.min(255, Math.round(parseFloat(raw))));
            return (n < 16 ? '0' : '') + n.toString(16);
        }

        return '#' + channel(parts[1]) + channel(parts[2]) + channel(parts[3]);
    }

    function toPx(value) {
        var n = parseFloat(value);
        return isFinite(n) ? Math.round(n) : null;
    }

    // Prefer an element the shopper can actually see: a hidden offcanvas copy
    // of the nav is styled differently from the one on screen.
    function pickVisible(selectors) {
        var fallback = null;

        for (var i = 0; i < selectors.length; i++) {
            var nodes = document.querySelectorAll(selectors[i]);

            for (var j = 0; j < nodes.length; j++) {
                if (nodes[j].getClientRects().length > 0) {
                    return nodes[j];
                }
                if (fallback === null) {
                    fallback = nodes[j];
                }
            }
        }

        return fallback;
    }

    function styleOf(selectors) {
        var node = pickVisible(selectors);
        return node ? window.getComputedStyle(node) : null;
    }

    // An element with no border still reports a borderTopColor, because the
    // initial value of border-color is currentColor. Reading it regardless is
    // how you end up telling Reqser that a borderless card is outlined in the
    // body text colour, so the width decides whether the colour means anything.
    function borderColorOf(style) {
        if (style === null) {
            return null;
        }

        return parseFloat(style.borderTopWidth) > 0 ? toHex(style.borderTopColor) : null;
    }

    function designProbe() {
        var root = window.getComputedStyle(document.documentElement);
        var body = window.getComputedStyle(document.body);

        var cta = styleOf([
            '.btn-primary', '.product-detail-buy .btn', '.btn-buy',
            '.buy-widget .btn', 'form button[type="submit"].btn'
        ]);
        var card = styleOf(['.product-box', '.card', '.cms-block .card']);
        var header = styleOf(['.header-main', '.header-row', '.nav-main', 'header']);
        var input = styleOf(['.form-control', 'input[type="search"]', 'input[type="text"]']);
        var link = styleOf(['.main-navigation-link', 'a.nav-link', 'main a']);

        function variable(name) {
            var value = root.getPropertyValue(name);
            return value ? value.trim() : null;
        }

        return {
            page: window.location.origin + window.location.pathname,
            // Shopware themes are Bootstrap-based, so these carry the merchant's
            // theme configuration directly rather than as a rendered side effect.
            variables: {
                primary: variable('--bs-primary'),
                secondary: variable('--bs-secondary'),
                bodyBg: variable('--bs-body-bg'),
                bodyColor: variable('--bs-body-color'),
                borderColor: variable('--bs-border-color'),
                fontFamily: variable('--bs-body-font-family') || variable('--bs-font-sans-serif')
            },
            body: {
                fontFamily: body.fontFamily,
                fontSize: toPx(body.fontSize),
                color: toHex(body.color),
                background: toHex(body.backgroundColor)
            },
            cta: cta === null ? null : {
                background: toHex(cta.backgroundColor),
                color: toHex(cta.color),
                radius: toPx(cta.borderTopLeftRadius)
            },
            card: card === null ? null : {
                background: toHex(card.backgroundColor),
                border: borderColorOf(card),
                radius: toPx(card.borderTopLeftRadius),
                elevated: card.boxShadow !== 'none' && card.boxShadow !== ''
            },
            header: header === null ? null : {
                background: toHex(header.backgroundColor),
                color: toHex(header.color)
            },
            input: input === null ? null : {
                border: borderColorOf(input),
                radius: toPx(input.borderTopLeftRadius)
            },
            link: link === null ? null : {
                color: toHex(link.color)
            }
        };
    }

    function refreshCartWidget() {
        if (!window.PluginManager || typeof window.PluginManager.getPluginInstances !== 'function') {
            return;
        }

        (window.PluginManager.getPluginInstances('CartWidget') || []).forEach(function (instance) {
            if (instance && typeof instance.fetch === 'function') {
                instance.fetch();
            }
        });
    }

    window.addEventListener('message', function (event) {
        if (event.origin !== WIDGET_ORIGIN) {
            return;
        }
        if (!frame || event.source !== frame.contentWindow) {
            return;
        }

        var msg = event.data;
        if (!msg || typeof msg !== 'object' || msg.channel !== CHANNEL) {
            return;
        }

        // Every branch is a named capability. There is deliberately no generic
        // "call this route" case: the set of things the widget may ask the shop
        // to do is fixed here, in the shop's own code.
        switch (msg.type) {
            case 'ready':
                reply('hostContext', {
                    shopOrigin: window.location.origin,
                    page: window.location.pathname,
                    product: pageProduct()
                }, msg.requestId);
                break;
            case 'designProbe':
                reply('designProbe', { facts: designProbe() }, msg.requestId);
                break;
            case 'applyLauncher':
                applyLauncher(msg.style);
                break;
            case 'addToCart':
                addToCart(msg);
                break;
            case 'goTo':
                if (typeof msg.url === 'string' && msg.url.indexOf('/') === 0 && msg.url.indexOf('//') !== 0) {
                    window.location.assign(msg.url);
                }
                break;
            case 'open':
                toggle(true);
                break;
            case 'close':
                toggle(false);
                break;
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', build);
    } else {
        build();
    }
})();
JS;
    }
}
