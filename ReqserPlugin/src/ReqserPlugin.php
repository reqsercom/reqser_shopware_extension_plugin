<?php declare(strict_types=1);

namespace Reqser\Plugin;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;

class ReqserPlugin extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Add any activation logic here
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // Add any deactivation logic here
    }
}
