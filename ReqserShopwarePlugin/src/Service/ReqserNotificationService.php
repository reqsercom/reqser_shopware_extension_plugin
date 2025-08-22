<?php
declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

class ReqserNotificationService
{
    private $notificationRepository;

    public function __construct(EntityRepository $notificationRepository)
    {
        $this->notificationRepository = $notificationRepository;
    }

    public function sendAdminNotification(string $message, string $title = 'ReqserPlugin', string $status = 'success'): void
    {
        //use this
        //$this->notificationService->sendAdminNotification('StorefrontRenderEvent triggered');

        $context = Context::createDefaultContext();

        $this->notificationRepository->create([
            [
                'id' => Uuid::randomHex(),
                'status' => $status,
                'title' => $title,
                'message' => $message,
                'adminOnly' => true,
                'requiredPrivileges' => [],
                'createdByIntegrationId' => null,
                'createdByUserId' => null,
            ]
        ], $context);
    }
}
