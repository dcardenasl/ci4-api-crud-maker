# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## ⚡ Workflow — read this first

**Before touching any code, read `TASKS.md` in this directory.**

1. Take the first task from `## 🔴 En progreso` (if any) or `## 🟡 Próximo`
2. If taking from Próximo: move it to `## 🔴 En progreso`
3. Work exclusively on that task — if anything is unclear, ask before implementing
4. When done: move it to `## ✅ Completadas` with one line of notes (what you did and why)
5. Never work on tasks not defined in TASKS.md without explicit confirmation

For cross-repo context (current milestone, blocked tasks), read `../TASKS.md`.

## Repository Overview

**ci4-api-core** is a Composer package providing the **runtime foundation** for CodeIgniter 4 API projects: base classes, HTTP layer, services, repositories, models, filters, audit chain, queue, and security utilities. Designed to drop into any CodeIgniter 4 application via Packagist (`dcardenasl/ci4-api-core`).

**Current version:** v1.3.0 — published on Packagist.

For release history see `CHANGELOG.md`. Current state of this package:

- **Repositories**: `BaseRepository`, `GenericRepository`, `AuditRepositoryInterface`, `PivotRepositoryInterface`
- **Exceptions**: `ApiException` base + `AuthenticationException`, `AuthorizationException`, `ConflictException`, `ServiceUnavailableException`, `TooManyRequestsException`, `ValidationException`, `BadRequestException`, `NotFoundException`
- **Mappers / Support**: `DtoResponseMapper`, `RelationLabelLoader`, `RequestDtoFactory`, `RequestDataCollector`, `ResponseDtoFactory`, `RequestAuditContextFactory`, `ResolvesWebAppLinks`, `ApiConfigFacade`, `OperationResult`, `OperationState` enum
- **HTTP layer**: `ApiController`, `ApiRequest`, `ApiResponse`, `ContextHolder`, `RequestIdHolder`
- **HTTP filters (concrete)**: 9 filters — `CorrelationIdFilter`, `CorsFilter`, `DeprecationHeadersFilter`, `FeatureToggleFilter`, `IdempotencyFilter`, `LocaleFilter`, `MaintenanceFilter`, `RequestLoggingFilter`, `SecurityHeadersFilter`
- **HTTP filters (abstract)**: 3 abstract bases consumers extend with their JWT/IAM concretions — `AbstractJwtAuthFilter`, `AbstractPermissionFilter`, `AbstractThrottleFilter`
- **IAM**: `Contracts\Iam\PermissionResolverInterface`, `Contracts\Iam\ApplicationPermissionResolverInterface`, `Contracts\SecurityAuditLoggerInterface`, `Services\Iam\AbstractIamAuthorizationService`
- **Query filters**: `FilterParser`, `FilterOperatorApplier`, `SearchQueryApplier`, `SearchProfile`, `FulltextIndexInspector`, `QueryBuilder`
- **Logging / Monitoring / Queue**: `JsonFormatter`, `MonologHandler`, `HealthChecker`, `Queue\Job`, `QueueManager`, `SyncQueueManager`, `WriteAuditLogJob`, `LogRequestJob`
- **Audit chain**: `AuditService`, `AuditWriter`, `AuditPayloadSanitizer`, `AuditEventDTO`, `PayloadResponseDTO`
- **Models**: `BaseAuditableModel`, `BaseTranslationModel`, `BasePublicSlugModel`, `Auditable` trait, `Filterable`, `Searchable` traits, `DecimalCast`
- **Content localization**: `RequestLocaleResolver`, `SlugGenerator`, `LocalizedTranslationStore`, `PublicSlugStore`, `NormalizesLocalizedPayload`, `Config\Localization`, `HasLocalizedTranslations`, `HasPublicSlugs`
- **Security**: `Security\Hasher`, `Security\Token`, `Security\Mask`
- **Helpers**: `src/Helpers/date.php` (autoloaded via `composer files`)

For **CRUD generation** (scaffolding), use the companion package: `dcardenasl/ci4-api-scaffolding` (add to `require-dev`).

## Commands

```bash
composer test       # PHPUnit (Unit, Integration, Database; Database needs MySQL)
composer analyse    # PHPStan level 8
composer cs-check   # PHP CS-Fixer dry-run
composer cs-fix     # Apply PHP CS-Fixer style fixes
composer security   # composer audit --no-dev --locked
composer quality    # analyse + cs-check + security + test
```

**Spark commands (available in consumer projects):**

```bash
php spark core:install   # One-shot wiring: writes ApiCoreServices.php, patches Services.php
                         # (idempotent, with .bak backup and fail-safe recovery snippet)
php spark core:check     # Verify all 4 required Service factories are wired
php spark env:check      # Validate required env vars + secret strength + prod CORS gate
php spark queue:work     # Run the bundled QueueManager worker (--once, --max-jobs, --queue, ...)
```

Scaffolding commands (`make:crud`, `make:crud:remove`, `module:check`, `swagger:generate`, `scaffold:check`) and shell wrappers (`make-crud.sh`, `validate-crud.sh`) live in `ci4-api-scaffolding`. See that package's README.

## Architecture

| Directory | Purpose |
|---|---|
| `src/Http/` | `ApiController`, `ApiResponse`, `ApiRequest`, `ContextHolder`, `RequestIdHolder` (HTTP boundary base classes) |
| `src/Http/Filters/` | 9 HTTP filters: `CorrelationIdFilter`, `CorsFilter`, `DeprecationHeadersFilter`, `FeatureToggleFilter`, `IdempotencyFilter`, `LocaleFilter`, `MaintenanceFilter`, `RequestLoggingFilter`, `SecurityHeadersFilter` |
| `src/Services/` | `BaseCrudService`, `CrudServiceContract`, `HandlesTransactions`, `HasLocalizedTranslations`, `HasPublicSlugs`, `AuditServiceInterface`, `AuditService` (concrete), `AuditWriter`, `AuditPayloadSanitizer` |
| `src/Models/` | `BaseAuditableModel` + `Auditable` trait (audit hooks for CI4 models), `BaseTranslationModel`, `BasePublicSlugModel` |
| `src/Models/Traits/` | `Filterable`, `Searchable` (model-level whitelisted query helpers) |
| `src/Filters/` | `FilterParser`, `FilterOperatorApplier`, `SearchQueryApplier`, `QueryBuilder` (request → query plumbing the traits delegate to) |
| `src/DataCasts/` | `DecimalCast` (string-backed CI4 Entity cast preserving DECIMAL precision) |
| `src/Dto/` | `DataTransferObjectInterface`, `BaseRequestDTO`, `PaginatedResponseDTO`, `SecurityContext`, `Concerns\NormalizesLocalizedPayload` |
| `src/Localization/` | `RequestLocaleResolver`, `SlugGenerator`, `LocalizedTranslationStore`, `PublicSlugStore` |
| `src/Config/` | `Api`, `Audit`, `Cors`, `FeatureFlags`, `Localization`, `Queue` |
| `src/Repositories/` | `RepositoryInterface`, `BaseRepository`, `GenericRepository`, `AuditRepositoryInterface`, `PivotRepositoryInterface` |
| `src/Mappers/` | `ResponseMapperInterface`, `DtoResponseMapper` (entity → DTO contract + default implementation) |
| `src/Exceptions/` | `ApiException` base + 8 concrete exceptions + `HasStatusCode` trait |
| `src/Support/` | `ApiResult`, `OperationResult`, `OperationState` enum, `ExceptionFormatter`, `ApiConfigFacade` (value objects + utilities) |
| `src/Security/` | `Hasher`, `Token`, `Mask` (namespaced replacements for removed procedural helpers) |
| `src/Request/` | `RequestHelper` (namespaced replacement for removed procedural helpers) |
| `src/Queue/` | `Job` base, `QueueManager`, `SyncQueueManager`, `WriteAuditLogJob`, `LogRequestJob` |
| `src/Logging/` | `JsonFormatter`, `MonologHandler` (structured JSON logging via monolog) |
| `src/Monitoring/` | `HealthChecker` |
| `src/Contracts/` | `AuditableModelInterface`, `PaginatableResponse` (marker interface for paginated DTOs) |
| `docs/` | `ARCHITECTURE_CONTRACT.md` (non-negotiable layer rules for consumer modules) · `EXTENDING_IAM.md` (plug in another identity provider) · `EXTENDING_THROTTLE.md` (custom rate-limit strategy) · `EXTENDING_QUEUE.md` (alternative queue backend) · `EXTENDING_AUDIT.md` (replace or extend the audit pipeline) · `EXTENDING_LOCALIZATION.md` (localized content and public slugs) |

### Consumer requirements (runtime contract)

Classes under `src/Http/`, `src/Models/`, `src/Services/`, and `src/Filters/` rely on a few CI4-host symbols that this package cannot bundle. Any consumer must provide the following in its own `app/Config/Services.php`:

- `Services::auditService()` → instance of `dcardenasl\Ci4ApiCore\Services\AuditServiceInterface` (used by `BaseAuditableModel::initialize()`)
- `Services::requestAuditContextFactory()` → object with `buildMetadata(ApiRequest): array` (used by `ApiController::buildRequestMetadata()`)
- `Services::requestDtoFactory()` → object with `make(class-string, array): BaseRequestDTO` (used by `ApiController::executeTarget()`)
- `Services::requestDataCollector()` → object with `collect(ApiRequest, ?array): array` (used by `ApiController::collectRequestData()`)
- **`config('Api')`** *(optional)* → CI4 Config object exposing `searchEnabled: bool`, `searchUseFulltext: bool`, `searchMinLength: int`, `paginationDefaultLimit: int`, `paginationMaxLimit: int`. Read by `Filters\SearchQueryApplier` and `Filters\QueryBuilder`. **Each knob defaults safely** when the config or property is absent (`searchEnabled=true`, `searchUseFulltext=true`, `searchMinLength=0`, `paginationDefaultLimit=20`, `paginationMaxLimit=100`), so vanilla consumers without a `Config\Api` class still get a working search and pagination out of the box.

Plus standard CI4 globals: `lang()`, `service('validation')`, `Config\Database`, `ENVIRONMENT` constant.

Consumers adopting localized content must additionally provide:

- `Services::requestLocaleResolver()` → shared `dcardenasl\Ci4ApiCore\Localization\RequestLocaleResolver`
  used by locale-aware stores; `LocaleFilter` uses the same parser contract for app-locale negotiation
- `Services::localizedTranslationStore()` → `dcardenasl\Ci4ApiCore\Localization\LocalizedTranslationStore`
  built with the consumer's `BaseTranslationModel` subclass
- `Services::publicSlugStore()` → `dcardenasl\Ci4ApiCore\Localization\PublicSlugStore` built with the
  consumer's `BasePublicSlugModel` subclass when public slugs are enabled
- **`config('Localization')`** → consumer subclass of `dcardenasl\Ci4ApiCore\Config\Localization` with
  `translatableFields` and `legacyFallbackLocale`

These are not enforced at install time — a missing factory (factories 1–4) will surface as a `BadMethodCallException` on the first request that hits the corresponding code path. The optional `config('Api')` (5) silently coalesces to defaults.
Localization factories and `config('Localization')` are likewise optional until a service composes the
localization traits; at that point the consumer must wire the factories and registry described above.

## Key rules

- **This is a Composer package** — changes here affect all consumer projects. Breaking changes require a version bump.
- **Run `composer quality` before any merge** — PHPStan level 8 + PHPUnit + CS check + security audit. All must be clean.
- **`docs/ARCHITECTURE_CONTRACT.md` is the authority** — any copy that lives in a downstream consumer is a reference snapshot only.
- **Scaffolding lives in `ci4-api-scaffolding`** — do not add generators, spark commands, or field types here.
- **Do not introduce procedural helpers** — use namespaced classes (`Security\Hasher`, `Request\RequestHelper`, `Support\DateHelper`).

## Companion package

CRUD scaffolding (generators, spark commands, `make-crud.sh`) lives in the sibling Composer package `dcardenasl/ci4-api-scaffolding`. Add it to `require-dev` in consumer projects:

```bash
composer require --dev dcardenasl/ci4-api-scaffolding:^0.7
```

See that package's README for field syntax, scaffolding contract, customization, and troubleshooting.
