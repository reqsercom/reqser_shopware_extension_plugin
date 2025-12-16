<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\Twig;

use Reqser\Plugin\Service\ReqserFlagService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension to provide flag helper functions in templates
 */
class ReqserFlagExtension extends AbstractExtension
{
    private ReqserFlagService $flagService;

    public function __construct(ReqserFlagService $flagService)
    {
        $this->flagService = $flagService;
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('reqser_flag_path', [$this->flagService, 'getFlagPath']),
            new TwigFunction('reqser_flag_class', [$this->flagService, 'getFlagClass']),
            new TwigFunction('reqser_swag_language_pack_installed', [$this->flagService, 'isSwagLanguagePackInstalled']),
        ];
    }
}

