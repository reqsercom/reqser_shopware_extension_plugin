# Version Differences: 1.6.x (Shopware 6.4/6.5) vs 1.7.x (Shopware 6.5) vs 2.x (Shopware 6.6/6.7)

This document describes the technical differences between the **1.6.x branch** (targeting Shopware 6.4.11–6.5.7), the **legacy 1.7.x branch** (targeting Shopware 6.5.8–6.5.x), and the **main 2.x branch** (targeting Shopware 6.6/6.7). It focuses on Shopware core API changes, Symfony routing, message handling, and removed features.

## Version Overview

| Branch   | Plugin Version | Shopware Core Requirement     | Symfony Version         | Route Loader Type |
|----------|---------------|-------------------------------|-------------------------|-------------------|
| main     | 2.x.x         | `~6.6.0 \|\| ~6.7.0`         | ~6.4 / ~7.0            | `attribute`       |
| 1.7.x    | 1.7.x         | `>=6.5.8 <6.6.0`             | ~6.4                    | `attribute`       |
| 1.6.x    | 1.6.x         | `>=6.4.11 <6.5.8`            | ~5.4 / ~6.x (<6.4)     | `annotation`      |
| 1.5.x    | 1.5.x         | `>=6.4.0 <6.4.11`            | ~5.4                    | `annotation`      |

---

## 1. Composer Requirements

### Main (2.x)
```json
{
    "require": {
        "shopware/core": "~6.6.0 || ~6.7.0"
    },
    "conflict": {
        "shopware/core": "<6.6.0"
    }
}
```

### Legacy 1.7.x
```json
{
    "require": {
        "shopware/core": ">=6.5.8 <6.6.0"
    },
    "conflict": {
        "shopware/core": "<6.5.8",
        "symfony/routing": "<6.4"
    }
}
```

### Legacy 1.6.x
```json
{
    "require": {
        "shopware/core": ">=6.4.11 <6.5.8"
    },
    "conflict": {
        "shopware/core": "<6.4.11",
        "symfony/routing": ">=6.4"
    }
}
```

### Symfony Routing Conflict Boundary

The `symfony/routing` conflict is the key differentiator between 1.6.x and 1.7.x:

- **1.6.x** conflicts with `symfony/routing: >=6.4` — ensures the plugin stays on Symfony <6.4, which matches Shopware 6.4.x and early 6.5.x (these ship with Symfony ~5.4 or ~6.2/~6.3).
- **1.7.x** conflicts with `symfony/routing: <6.4` — ensures the plugin runs on Symfony 6.4+, which matches Shopware 6.5.8+ (these ship with Symfony ~6.4).
- **2.x** has no `symfony/routing` conflict — it supports both Symfony ~6.4 (Shopware 6.6) and potentially Symfony ~7.0 (Shopware 6.7).

This boundary determines which routing namespace and route loader type the plugin must use.

---

## 2. Symfony Routing

### 2.1 Route Import Type (`routes.xml`)

The `routes.xml` file controls how Symfony discovers route definitions in the controller directories.

| Branch | Loader Type | Reads Docblock `@Route` | Reads PHP 8 `#[Route]` |
|--------|------------|------------------------|------------------------|
| 1.6.x  | `type="annotation"` | Yes | Yes (Symfony 5.2+) |
| 1.7.x  | `type="attribute"`  | No  | Yes |
| 2.x    | `type="attribute"`  | No  | Yes |

**Why the change from 1.6.x to 1.7.x:**

In Symfony 6.4, `type="annotation"` was deprecated and aliased to `type="attribute"`. The annotation loader was removed entirely in Symfony 7.0. Since 1.7.x targets Symfony 6.4, it switches to `type="attribute"` to avoid deprecation warnings. This also means 1.7.x no longer supports `@Route` docblock annotations — only `#[Route]` PHP 8 attributes.

### 2.2 Route Namespace

Symfony provides two namespaces for the Route attribute:

| Namespace | Available Since | Deprecated In | Removed In |
|-----------|----------------|---------------|------------|
| `Symfony\Component\Routing\Annotation\Route` | Symfony 5.x | Symfony 6.4 | Symfony 7.0 |
| `Symfony\Component\Routing\Attribute\Route`   | Symfony 6.2 | — | — |

**Current state per branch:**

| Controller | 1.6.x | 1.7.x | 2.x (main) |
|------------|-------|-------|-------------|
| `ReqserCmsApiController` | `Annotation\Route` | `Annotation\Route` | `Annotation\Route` |
| `ReqserDatabaseApiController` | `Annotation\Route` | `Annotation\Route` | `Annotation\Route` |
| `ReqserSnippetListApiController` | `Annotation\Route` | `Annotation\Route` | `Annotation\Route` |
| `ReqserAnalyticsApiController` | `Annotation\Route` | `Annotation\Route` | `Annotation\Route` |
| `ReqserLanguageDetectionController` | `Annotation\Route` | `Attribute\Route` | `Attribute\Route` |

The API controllers use `Annotation\Route` on **all branches**. This works on Symfony 5.4+ and Symfony 6.4 (deprecated but functional). On Symfony 7.0, `Annotation\Route` is **removed** — if Shopware 6.7 ships with Symfony 7.0, the API controllers on the main branch must be migrated to `Attribute\Route`.

The storefront controller (`ReqserLanguageDetectionController`) was migrated to `Attribute\Route` starting from 1.7.x.

### 2.3 Route Definition Style

| Controller | 1.6.x | 1.7.x | 2.x (main) |
|------------|-------|-------|-------------|
| API controllers (4) | `#[Route(...)]` attribute | `#[Route(...)]` attribute | `#[Route(...)]` attribute |
| `ReqserLanguageDetectionController` | `@Route(...)` docblock | `#[Route(...)]` attribute | `#[Route(...)]` attribute |

**1.6.x routing style note:** The four API controllers use PHP 8 `#[Route]` attributes, while `ReqserLanguageDetectionController` uses `@Route` docblock annotations.

Both styles are valid on the 1.6.x target (Symfony 5.4+) because:
- The `type="annotation"` route loader in Symfony 5.2+ reads **both** docblock annotations and PHP 8 attributes.
- The `Annotation\Route` class has the `#[\Attribute]` marker since Symfony 5.2, so it works as a PHP 8 attribute.

Starting from 1.7.x, `type="attribute"` only reads PHP 8 attributes, which is why the storefront controller was migrated from `@Route` docblock to `#[Route]` attribute.

### 2.4 Route Scope Definition

All branches define route scopes identically using the `defaults` parameter:

```php
// API controllers
#[Route(defaults: ['_routeScope' => ['api']])]

// Storefront controllers
#[Route(defaults: ['_routeScope' => ['storefront']])]
```

On 1.6.x, the storefront controller uses the docblock equivalent:

```php
/** @Route(defaults={"_routeScope"={"storefront"}}) */
```

This is the standard Shopware 6.5+ approach. Older versions (6.4.x, covered by 1.5.x/1.6.x) also support this format because `_routeScope` was introduced in Shopware 6.4 as part of the route defaults migration from `@RouteScope` annotations.

---

## 3. Scheduled Task / Message Handler Registration

Shopware changed how scheduled task handlers register themselves between 6.5 and 6.6.

### Main (2.x) — Shopware 6.6+ Style

Uses the `#[AsMessageHandler]` PHP attribute from Symfony Messenger:

```php
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: ReqserNotificiationRemoval::class)]
class ReqserNotificiationRemovalHandler extends ScheduledTaskHandler
{
    // No getHandledMessages() method needed
}
```

### Legacy 1.7.x and 1.6.x — Shopware 6.4/6.5 Style

Uses the `getHandledMessages()` static method override:

```php
class ReqserNotificiationRemovalHandler extends ScheduledTaskHandler
{
    //Needed for Shopware <=6.5 support, not necessary from 6.6+
    public static function getHandledMessages(): iterable
    {
        return [ReqserNotificiationRemoval::class];
    }
}
```

Both 1.6.x and 1.7.x use this approach because their target Shopware versions (6.4 and 6.5) rely on the `getHandledMessages()` method to register which message classes the handler processes. The `<tag name="messenger.message_handler" />` tag in `services.xml` is required on both branches.

Shopware 6.6 adopted Symfony's native `#[AsMessageHandler]` attribute, making the static method unnecessary. The `#[AsMessageHandler]` attribute is not recognized by the Shopware 6.4/6.5 message bus configuration.

---

## 4. Snippet Collection API (Removed from All Branches)

The legacy Snippet Collection API (`ReqserSnippetApiController` at `/api/_action/reqser/snippets/collect` and `ReqserSnippetApiService`) has been **removed from all branches**. It was previously present on 1.6.x but was never registered in `services.xml` (no proper DI configuration) and was already deleted on 1.7.x and 2.x. It has now been removed from 1.6.x as well to align all branches.

The snippet collection functionality was replaced by `ReqserSnippetListApiController` and `ReqserSnippetListService` which use Shopware's native `SnippetService` instead of direct JSON file scanning. These are properly registered in `services.xml` and exist on all branches.

---

## 5. Webhook Rate Limiting (`ReqserEntityWebhookSubscriber`)

### Present on All Branches (Identical Code)

The `ReqserEntityWebhookSubscriber` is present and **identical** across all three branches (1.6.x, 1.7.x, and 2.x). The subscriber, its cache pool service (`reqser.webhook_filter.cache`), and its service definition in `services.xml` are unchanged.

The subscriber filters webhook dispatches for `product.written` and `category.written` events before Shopware sends them. It enforces:

- **Admin-only**: Only webhooks triggered by a logged-in admin user (manual save) pass through. Automated processes (PIM syncs, CLI imports, integrations) are blocked.
- **Rate limiting**: Maximum 1 webhook per 10 seconds per entity type (cooldown), maximum 100 per day per entity type.

The subscriber hooks into two events:

1. `EntityWrittenContainerEvent` — captures whether the write originated from an admin user
2. `PreWebhooksDispatchEvent` — filters the webhook list before Shopware dispatches them

### Functional Status per Shopware Version

| Shopware Version | `EntityWrittenContainerEvent` | `PreWebhooksDispatchEvent` | Subscriber Functional? |
|------------------|:---:|:---:|:---:|
| 6.4.x (1.6.x) | Exists | **Does not exist** | Partially (only `onEntityWritten` fires) |
| 6.5.x (1.7.x) | Exists | **Does not exist** | Partially (only `onEntityWritten` fires) |
| 6.6.x+ (2.x)  | Exists | Exists | **Fully functional** |

### Why It Does Not Crash on Shopware 6.4/6.5

The subscriber references two classes that were **introduced in Shopware 6.6**:

1. **`Shopware\Core\Framework\Webhook\Event\PreWebhooksDispatchEvent`** — used in `getSubscribedEvents()` as `PreWebhooksDispatchEvent::class`
2. **`Shopware\Core\Framework\Webhook\Webhook`** (value object / DTO) — used as a type hint in the private `shouldFilterWebhook(Webhook $webhook)` method

This does **not** cause a fatal error because:

- PHP `use` statements are alias declarations and do **not** trigger autoloading.
- `PreWebhooksDispatchEvent::class` resolves to a string at compile time without autoloading the class.
- Symfony's EventDispatcher stores the event name as a string — if no event with that name is ever dispatched, the handler is simply never called.
- The `Webhook` type hint in `shouldFilterWebhook()` is only resolved if the method is actually invoked, which never happens since `onPreWebhooksDispatch()` is never called.

**Result on 1.6.x / 1.7.x:** The `onEntityWritten()` method fires on every entity write (setting the `$isAdminUserAction` flag), but `onPreWebhooksDispatch()` is never called. The webhook rate limiting has no effect. This is dead code on these branches.

### How Webhooks Work in Shopware 6.5 (No Interception Point)

In Shopware 6.5, the `WebhookDispatcher` service decorates Symfony's event dispatcher. When any entity event fires (e.g., `product.written`), the `WebhookDispatcher`:

1. Dispatches the event normally through the inner event dispatcher
2. Internally loads matching `WebhookEntity` records from the database
3. Dispatches `WebhookEventMessage` objects to the Symfony Messenger bus
4. The message handler then sends the HTTP requests

There is **no public event or hook point** between steps 2 and 3 where a subscriber could filter which webhooks get dispatched. The `dispatchWebhooks()` method is private within `WebhookDispatcher`.

---

## 6. Services Configuration (`services.xml`)

### Identical Across All Branches

The `services.xml` file is **identical** on the 1.6.x, 1.7.x, and 2.x branches. All service definitions — including `ReqserEntityWebhookSubscriber`, `reqser.webhook_filter.cache`, `ReqserAnalyticsService`, `ReqserCustomFieldUsageService`, `ReqserAnalyticsApiController`, and all other services — are unchanged.

### Services Registered

The following services are registered on all branches:

**Subscribers:**
- `ReqserLanguageSwitchSubscriber`
- `ReqserProductReviewSubscriber`
- `ReqserEntityWebhookSubscriber` (+ `reqser.webhook_filter.cache` pool)
- `ReqserPluginVersionCheckSubscriber`

**Core Services:**
- `ReqserNotificationService`, `ReqserWebhookService`, `ReqserAppService`
- `ReqserSessionService`, `ReqserCustomFieldService`, `ReqserFlagService`
- `ReqserLanguageRedirectService`, `ReqserVersionService`
- `ReqserFlagExtension` (Twig extension)

**API Services:**
- `ReqserApiAuthService`, `ReqserSnippetListService`
- `ReqserJsonFieldDetectionService`, `ReqserDatabaseService`
- `ReqserCmsTwigFileService`, `ReqserCustomFieldUsageService`, `ReqserCmsRenderService`
- `ReqserAnalyticsService`

**Controllers:**
- `ReqserLanguageDetectionController`
- `ReqserSnippetListApiController`, `ReqserDatabaseApiController`
- `ReqserAnalyticsApiController`, `ReqserCmsApiController`

### Consistent Across All Branches

All controllers and services are identically registered across all branches. The only intentional difference between branches is the webhook rate limiter, which is only functional on 2.x (see Section 5).

---

## 7. Correctness Assessment

### 1.6.x (Shopware 6.4.11–6.5.7, Symfony ~5.4 / ~6.x <6.4)

| Aspect | Status | Notes |
|--------|--------|-------|
| `type="annotation"` in routes.xml | Correct | Required for Symfony <6.4 |
| `@Route` docblock annotations | Correct | Supported by Symfony 5.4+ annotation loader |
| `#[Route]` with `Annotation\Route` | Correct | `Annotation\Route` is a valid PHP 8 attribute since Symfony 5.2 |
| `getHandledMessages()` for tasks | Correct | Required for Shopware 6.4/6.5 |
| `<tag name="messenger.message_handler" />` | Correct | Required for Shopware 6.4/6.5 |
| `symfony/routing: >=6.4` conflict | Correct | Prevents installation on Symfony 6.4+ |
| `ReqserEntityWebhookSubscriber` | Safe but dead code | `PreWebhooksDispatchEvent` does not exist; subscriber is non-functional |

### 1.7.x (Shopware 6.5.8–6.5.x, Symfony ~6.4)

| Aspect | Status | Notes |
|--------|--------|-------|
| `type="attribute"` in routes.xml | Correct | Required for Symfony 6.4 (annotation type deprecated) |
| `#[Route]` with `Attribute\Route` (storefront) | Correct | Proper namespace for Symfony 6.4 |
| `#[Route]` with `Annotation\Route` (API controllers) | Correct (deprecated) | Works on Symfony 6.4 but deprecated; would break on Symfony 7.0 |
| `getHandledMessages()` for tasks | Correct | Required for Shopware 6.5 |
| `symfony/routing: <6.4` conflict | Correct | Ensures minimum Symfony 6.4 |
| `ReqserEntityWebhookSubscriber` | Safe but dead code | `PreWebhooksDispatchEvent` does not exist in 6.5; subscriber is non-functional |
| Snippet collection API removed | Correct | Was never properly registered; replaced by snippet list API |

### 2.x (Shopware 6.6/6.7, Symfony ~6.4 / ~7.0)

| Aspect | Status | Notes |
|--------|--------|-------|
| `type="attribute"` in routes.xml | Correct | Required for Symfony 6.4+ / 7.0 |
| `#[Route]` with `Attribute\Route` (storefront) | Correct | Works on Symfony 6.4 and 7.0 |
| `#[Route]` with `Annotation\Route` (API controllers) | Risk | Works on Symfony 6.4 (deprecated), **breaks on Symfony 7.0** where `Annotation\Route` is removed |
| `#[AsMessageHandler]` for tasks | Correct | Required for Shopware 6.6+ |
| No `symfony/routing` conflict | Correct | Supports both Symfony 6.4 and 7.0 |
| `ReqserEntityWebhookSubscriber` | Fully functional | `PreWebhooksDispatchEvent` exists in Shopware 6.6+ |

---

## 8. Transition Summary: 1.6.x to 1.7.x

The 1.6.x → 1.7.x transition crosses the **Symfony 6.4 boundary**. The key changes are:

| Change | Details |
|--------|---------|
| **Shopware version target** | `>=6.4.11 <6.5.8` → `>=6.5.8 <6.6.0` |
| **Symfony routing conflict** | `>=6.4` → `<6.4` (boundary flipped) |
| **Route loader type** | `type="annotation"` → `type="attribute"` |
| **Storefront controller** | `@Route` docblock with `Annotation\Route` → `#[Route]` attribute with `Attribute\Route` |
| **Snippet Collection API** | Removed (was never properly registered in services.xml; already deleted on 1.7.x) |
| **API controllers** | Unchanged (`Annotation\Route` with `#[Route]` attribute) |
| **Scheduled task handler** | Unchanged (`getHandledMessages()`) |
| **Webhook subscriber** | Unchanged (present but non-functional on both) |
| **Services.xml** | Unchanged |

---

## 9. Transition Summary: 1.7.x to 2.x

The 1.7.x → 2.x transition crosses the **Shopware 6.6 boundary**. The key changes are:

| Change | Details |
|--------|---------|
| **Shopware version target** | `>=6.5.8 <6.6.0` → `~6.6.0 \|\| ~6.7.0` |
| **Symfony routing conflict** | `<6.4` → removed |
| **Scheduled task handler** | `getHandledMessages()` → `#[AsMessageHandler]` attribute |
| **Webhook subscriber** | Non-functional → **Fully functional** (`PreWebhooksDispatchEvent` now exists) |
| **Route loader type** | Unchanged (`type="attribute"`) |
| **Storefront controller** | Unchanged (`Attribute\Route` with `#[Route]` attribute) |
| **API controllers** | Unchanged (`Annotation\Route` with `#[Route]` attribute) |
| **Services.xml** | Unchanged |

---

## 10. Feature Parity Summary

| Feature                              | 1.6.x | 1.7.x | 2.x (main) | Notes |
|--------------------------------------|:-----:|:-----:|:----------:|-------|
| Analytics API (language distribution)| Yes   | Yes   | Yes        | — |
| Custom field Twig usage analysis     | Yes   | Yes   | Yes        | — |
| CMS element rendering API            | Yes   | Yes   | Yes        | — |
| Database/translation table API       | Yes   | Yes   | Yes        | — |
| Snippet list API                     | Yes   | Yes   | Yes        | — |
| Snippet collection API (JSON files)  | **No** | **No** | **No**  | Removed from all branches; replaced by snippet list API |
| Webhook rate limiting                | Dead code | Dead code | **Yes** | `PreWebhooksDispatchEvent` only exists in Shopware 6.6+ |
| `#[AsMessageHandler]` attribute      | No    | No    | **Yes**    | Uses `getHandledMessages()` on 1.6.x/1.7.x |
| `type="attribute"` route loader      | No    | **Yes** | **Yes**  | 1.6.x uses `type="annotation"` |

---

## 11. Known Issues and Recommendations

### API Controller Route Namespace (All Branches)

All four API controllers (`ReqserCmsApiController`, `ReqserDatabaseApiController`, `ReqserSnippetListApiController`, `ReqserAnalyticsApiController`) use `Symfony\Component\Routing\Annotation\Route` on all branches. This namespace is:

- **Functional** on Symfony 5.4, 6.x (including 6.4)
- **Deprecated** on Symfony 6.4
- **Removed** on Symfony 7.0

**Recommendation for 2.x:** Migrate all API controllers to `Symfony\Component\Routing\Attribute\Route` before Shopware 6.7 ships with Symfony 7.0. This is a low-risk change (find-and-replace in the `use` statement).

### Webhook Subscriber on 1.6.x / 1.7.x

The `ReqserEntityWebhookSubscriber` is present as dead code on 1.6.x and 1.7.x. While it does not cause errors, the `onEntityWritten()` method runs on every entity write operation (setting a flag that is never read). This has negligible performance impact but is unnecessary.

**Recommendation:** Consider removing the subscriber from 1.6.x and 1.7.x branches if further maintenance is planned, or leave as-is since both versions are approaching end of life.

### Controller and Route Parity

All branches now share the same set of controllers and routes. The only functional difference is the webhook rate limiter (`ReqserEntityWebhookSubscriber`), which is present on all branches but only operational on 2.x where `PreWebhooksDispatchEvent` exists.
