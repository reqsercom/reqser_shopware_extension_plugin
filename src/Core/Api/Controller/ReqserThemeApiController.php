<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Reqser\Plugin\Service\ReqserApiAuthService;
use Reqser\Plugin\Service\ReqserThemeConfigService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin API controller for dumping every Shopware theme + its
 * translations. Used by the Reqser backend's on-demand
 * `shop-fresh-fetch` webhook (theme_config fetcher) when diagnosing
 * tickets where per-theme custom fields or per-language UI text might
 * carry untranslated content that the DAL sync misses.
 *
 * Shipped in plugin 2.0.23.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReqserThemeApiController extends AbstractController
{
    private ReqserThemeConfigService $themeConfigService;
    private ReqserApiAuthService $authService;

    public function __construct(
        ReqserThemeConfigService $themeConfigService,
        ReqserApiAuthService $authService
    ) {
        $this->themeConfigService = $themeConfigService;
        $this->authService = $authService;
    }

    /**
     * GET /api/_action/reqser/theme/config
     *
     * Response envelope mirrors the other Reqser admin routes:
     *   {
     *     "success": true,
     *     "data": { "themes": [...], "count": N },
     *     "timestamp": "YYYY-mm-dd HH:ii:ss"
     *   }
     *
     * On failure returns success=false with the exception details so
     * a ticket operator can triage without needing SSH access.
     */
    #[Route(
        path: '/api/_action/reqser/theme/config',
        name: 'api.action.reqser.theme.config',
        methods: ['GET']
    )]
    public function getThemeConfig(Request $request, Context $context): JsonResponse
    {
        try {
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $themes = $this->themeConfigService->dumpThemes();

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'themes' => $themes,
                    'count'  => count($themes),
                ],
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error retrieving theme config',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}
