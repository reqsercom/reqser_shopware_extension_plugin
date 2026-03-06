<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Service\ReqserApiAuthService;
use Reqser\Plugin\Service\ReqserCustomFieldUsageService;
use Reqser\Plugin\Service\ReqserDatabaseService;
use Shopware\Core\Framework\Api\Response\ResponseFactoryInterface;
use Shopware\Core\Framework\Api\Sync\SyncBehavior;
use Shopware\Core\Framework\Api\Sync\SyncOperation;
use Shopware\Core\Framework\Api\Sync\SyncService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin API Controller for Reqser Database Operations
 * Accessible only via authenticated API requests
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReqserDatabaseApiController extends AbstractController
{
    private ReqserDatabaseService $databaseService;
    private ReqserApiAuthService $authService;
    private LoggerInterface $logger;
    private ReqserCustomFieldUsageService $customFieldUsageService;
    private DefinitionInstanceRegistry $definitionRegistry;
    private RequestCriteriaBuilder $criteriaBuilder;
    private SyncService $syncService;

    public function __construct(
        ReqserDatabaseService $databaseService,
        ReqserApiAuthService $authService,
        LoggerInterface $logger,
        ReqserCustomFieldUsageService $customFieldUsageService,
        DefinitionInstanceRegistry $definitionRegistry,
        RequestCriteriaBuilder $criteriaBuilder,
        SyncService $syncService
    ) {
        $this->databaseService = $databaseService;
        $this->authService = $authService;
        $this->logger = $logger;
        $this->customFieldUsageService = $customFieldUsageService;
        $this->definitionRegistry = $definitionRegistry;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->syncService = $syncService;
    }

    /**
     * API endpoint to get all database tables ending with _translation
     * 
     * Requires:
     * - Request MUST be authenticated via the Reqser App's integration credentials
     * - Reqser App must be active
     * - GET method only
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/database/translation-tables',
        name: 'api.action.reqser.database.translation_tables',
        methods: ['GET']
    )]
    public function getTranslationTables(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse; // Return error response if validation failed
            }

            // Get translation tables from database
            $tables = $this->databaseService->getTranslationTables();

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'tables' => $tables,
                    'count' => count($tables)
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Throwable $e) {
            // Return error in API response without creating Shopware log entries
            // This prevents log pollution and handles all errors gracefully
            return new JsonResponse([
                'success' => false,
                'error' => 'Error retrieving translation tables',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * API endpoint to get schema information for a specific translation table
     * Returns ALL columns plus a list of which columns are translatable
     * 
     * Requires:
     * - Request MUST be authenticated via the Reqser App's integration credentials
     * - Reqser App must be active
     * - GET method only
     * - tableName must end with '_translation' (security requirement)
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/database/translation-tables/{tableName}/{row}',
        name: 'api.action.reqser.database.translation_tables_schema',
        methods: ['GET'],
        defaults: ['row' => null]
    )]
    public function getTranslationTableSchema(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse; // Return error response if validation failed
            }

            // Get table name from route parameter
            $tableName = $request->attributes->get('tableName');

            if (empty($tableName)) {
                throw new \InvalidArgumentException("Table name is required");
            }

            // Get complete table schema with translatable columns list
            $result = $this->databaseService->getTranslatableColumnsSchema($tableName);
            $tableSchema = $result['schema'];
            $translatableRows = $result['translatableRows'];
            
            // Get optional row identifier from route
            $row = $request->attributes->get('row');

            if (!empty($row)) {
                if (isset($tableSchema[$row])) {
                    //lest get extended detals for this row
                    $rowData = $this->databaseService->getTranslationTableRowDetails($tableName, $row, $tableSchema[$row]);
                    return new JsonResponse([
                        'success' => true,
                        'data' => [
                            'tableName' => $tableName,
                            'row' => $row,
                            'rowData' => $rowData
                        ]
                    ]);
                } else {
                    throw new \InvalidArgumentException("Row identifier '$row' not found in table schema");
                }
            } else  {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'tableName' => $tableName,
                        'schema' => $tableSchema,
                        'translatableRows' => $translatableRows
                    ]
                ]);
            }
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid parameter',
                'message' => $e->getMessage()
            ], 400);
        } catch (\RuntimeException $e) {
            // Check if it's a "not found" error
            if (str_contains($e->getMessage(), 'No row found')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Row not found',
                    'message' => $e->getMessage()
                ], 404);
            }
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Database error',
                'message' => $e->getMessage()
            ], 500);
        } catch (\Throwable $e) {
            // Return error in API response without creating Shopware log entries
            return new JsonResponse([
                'success' => false,
                'error' => 'Error retrieving translation table schema',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * API endpoint to analyze which custom fields are referenced in Twig templates.
     * Returns each custom field name, its type, and the Twig files it appears in.
     * 
     * Requires:
     * - Request MUST be authenticated via the Reqser App's integration credentials
     * - Reqser App must be active
     * - GET method only
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/database/custom-field-usage',
        name: 'api.action.reqser.database.custom_field_usage',
        methods: ['GET']
    )]
    public function getCustomFieldUsage(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $result = $this->customFieldUsageService->getCustomFieldTwigUsage();

            return new JsonResponse([
                'success' => true,
                'data' => $result,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error analyzing custom field usage',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Proxy for Shopware's POST /api/search/{entity} that bypasses ACL.
     * Accepts the exact same request body, headers (sw-language-id), and returns
     * the exact same response format. Only translation-related entities are allowed.
     * 
     * Requires:
     * - Reqser App authentication
     * - Entity must have a corresponding _translation table (or be on the allowlist)
     * 
     * @param Request $request
     * @param Context $context
     * @param ResponseFactoryInterface $responseFactory Resolved per-request by Shopware's argument resolver
     * @param string $entity Entity name from the URL (e.g. 'product', 'category', 'snippet')
     * @return Response
     */
    #[Route(
        path: '/api/_action/reqser/translations/search/{entity}',
        name: 'api.action.reqser.translations.search',
        methods: ['POST']
    )]
    public function searchTranslationEntity(
        Request $request,
        Context $context,
        ResponseFactoryInterface $responseFactory,
        string $entity
    ): Response {
        try {
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $entityUnderscored = str_replace('-', '_', $entity);
            $this->validateEntityIsTranslationRelated($entityUnderscored);

            $definition = $this->definitionRegistry->getByEntityName($entityUnderscored);
            $repository = $this->definitionRegistry->getRepository($definition->getEntityName());

            $criteria = $this->criteriaBuilder->handleRequest(
                $request,
                new Criteria(),
                $definition,
                $context
            );

            $result = $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($repository, $criteria) {
                return $repository->search($criteria, $context);
            });

            return $responseFactory->createListingResponse($criteria, $result, $definition, $request, $context);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid entity',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error in search proxy',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Proxy for Shopware's GET /api/{entity} that bypasses ACL.
     * Accepts the same query parameters (page, limit, etc.) and returns
     * the exact same response format. Only translation-related entities are allowed.
     * 
     * Used for force_get_request_tables where POST search doesn't work
     * (e.g. system entities with null IDs like sales-channel-type).
     * 
     * Requires:
     * - Reqser App authentication
     * - Entity must have a corresponding _translation table (or be on the allowlist)
     * 
     * @param Request $request
     * @param Context $context
     * @param ResponseFactoryInterface $responseFactory Resolved per-request by Shopware's argument resolver
     * @param string $entity Entity name from the URL (e.g. 'sales-channel-type')
     * @return Response
     */
    #[Route(
        path: '/api/_action/reqser/translations/list/{entity}',
        name: 'api.action.reqser.translations.list',
        methods: ['GET']
    )]
    public function listTranslationEntity(
        Request $request,
        Context $context,
        ResponseFactoryInterface $responseFactory,
        string $entity
    ): Response {
        try {
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $entityUnderscored = str_replace('-', '_', $entity);
            $this->validateEntityIsTranslationRelated($entityUnderscored);

            $definition = $this->definitionRegistry->getByEntityName($entityUnderscored);
            $repository = $this->definitionRegistry->getRepository($definition->getEntityName());

            $criteria = $this->criteriaBuilder->handleRequest(
                $request,
                new Criteria(),
                $definition,
                $context
            );

            $result = $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($repository, $criteria) {
                return $repository->search($criteria, $context);
            });

            return $responseFactory->createListingResponse($criteria, $result, $definition, $request, $context);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid entity',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error in list proxy',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Proxy for Shopware's POST /api/_action/sync that bypasses ACL.
     * Accepts the exact same request body and returns the exact same response format.
     * Only entities ending with '_translation' are allowed in the sync payload.
     * 
     * Requires:
     * - Reqser App authentication
     * - Every entity in the sync payload must end with '_translation'
     * 
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/translations/sync',
        name: 'api.action.reqser.translations.sync',
        methods: ['POST']
    )]
    public function syncTranslationData(Request $request, Context $context): JsonResponse
    {
        try {
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $payload = $request->request->all();

            if (empty($payload)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Empty payload',
                    'message' => 'Sync payload must not be empty'
                ], 400);
            }

            $operations = [];
            foreach ($payload as $key => $operation) {
                if (!is_array($operation) || !isset($operation['entity'], $operation['action'], $operation['payload'])) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Invalid operation format',
                        'message' => "Operation '$key' must contain 'entity', 'action', and 'payload'"
                    ], 400);
                }

                $entityName = $operation['entity'];
                $action = strtolower($operation['action']);

                if ($action === 'delete') {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Forbidden action',
                        'message' => "Delete operations are not allowed through proxy routes. Only 'upsert' is permitted."
                    ], 400);
                }

                if (!str_ends_with($entityName, '_translation')) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Forbidden entity',
                        'message' => "Entity '$entityName' is not allowed. Only entities ending with '_translation' are permitted."
                    ], 400);
                }

                $this->databaseService->validateTranslationTable($entityName);

                $operations[] = new SyncOperation(
                    $key,
                    $entityName,
                    $operation['action'],
                    $operation['payload'],
                    $operation['criteria'] ?? []
                );
            }

            $result = $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($operations) {
                return $this->syncService->sync($operations, $context, new SyncBehavior());
            });

            return new JsonResponse($result);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid parameter',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error in sync proxy',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Proxy for Shopware's PATCH /api/{entity}/{id} that bypasses ACL.
     * Accepts the exact same request body and returns the exact same response (204 No Content).
     * Only translation-related entities are allowed.
     * 
     * Typical body for translation tables: {"translations": {"{langId}": {"field": "value"}}}
     * Typical body for snippets: {"translationKey": "...", "setId": "...", "value": "..."}
     * 
     * Requires:
     * - Reqser App authentication
     * - Entity must have a corresponding _translation table (or be on the allowlist)
     * 
     * @param Request $request
     * @param Context $context
     * @param string $entity Entity name from the URL (e.g. 'product', 'salutation', 'snippet')
     * @param string $id Entity UUID
     * @return Response
     */
    #[Route(
        path: '/api/_action/reqser/translations/{entity}/{id}',
        name: 'api.action.reqser.translations.patch',
        methods: ['PATCH']
    )]
    public function patchTranslationEntity(
        Request $request,
        Context $context,
        string $entity,
        string $id
    ): Response {
        try {
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $entityUnderscored = str_replace('-', '_', $entity);
            $this->validateEntityIsTranslationRelated($entityUnderscored);

            $definition = $this->definitionRegistry->getByEntityName($entityUnderscored);
            $repository = $this->definitionRegistry->getRepository($definition->getEntityName());

            $payload = $request->request->all();
            $payload['id'] = $id;

            $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($repository, $payload) {
                $repository->update([$payload], $context);
            });

            return new Response('', Response::HTTP_NO_CONTENT);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid entity',
                'message' => $e->getMessage()
            ], 400);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error in PATCH proxy',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Validate that an entity is allowed through the translation proxy routes.
     * The entity must have a corresponding _translation table in the database.
     * Entities like snippet and product_review use standard API routes with
     * app permissions and are not allowed through the proxy.
     * 
     * @param string $entityName Entity name in snake_case (e.g. 'product')
     * @throws \InvalidArgumentException If the entity is not translation-related
     */
    private function validateEntityIsTranslationRelated(string $entityName): void
    {
        $translationTable = $entityName . '_translation';
        $this->databaseService->validateTranslationTable($translationTable);
    }
}
