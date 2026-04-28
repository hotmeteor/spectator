# Spectator — Copilot Instructions

Spectator is a Laravel package that provides OpenAPI contract testing tools. It validates that HTTP requests and responses conform to an OpenAPI spec, complementing (not replacing) Laravel's functional tests.

## Commands

```bash
composer test          # Run full test suite
composer cs            # Fix code style with Laravel Pint
composer analyse       # Run PHPStan (level 6, src + config only)
composer all           # cs + test + analyse

# Run a single test file
vendor/bin/phpunit tests/RequestValidatorTest.php

# Run a single test method
vendor/bin/phpunit --filter test_validates_request_body tests/RequestValidatorTest.php
```

## Architecture

The package is built around a middleware-driven validation loop:

1. **`Spectator` facade** — thin facade over `RequestFactory` (singleton).
2. **`RequestFactory`** — holds the active spec name, path prefix, and captured exceptions (`requestException`, `responseException`). Parses and caches spec files. Uses the `Macroable` trait for extensibility.
3. **`Middleware`** — prepended to the `api` middleware group. On each request it resolves the spec, matches the route to a `PathItem`, then calls `RequestValidator` and `ResponseValidator`. Exceptions from validators are *captured* into `RequestFactory` (not thrown), allowing assertions to run after the response is returned.
4. **`RequestValidator` / `ResponseValidator`** — both extend `AbstractValidator`, which handles:
   - OpenAPI 3.0 → 3.1 `nullable` migration (converts `nullable: true` to `type: [T, null]` internally)
   - `readOnly`/`writeOnly` property filtering based on read/write mode
5. **`Assertions`** — mixed into `TestResponse` via `TestResponse::mixin(new Assertions)`. Each method returns a `Closure` (the mixin pattern). Assertions read the captured exceptions from `app('spectator')`.
6. **`SpectatorServiceProvider`** — registers the singleton, merges config, but only registers middleware and the `TestResponse` mixin when `App::runningInConsole()` (i.e., during tests).

## Key Conventions

### Test structure
- Tests extend `Spectator\Tests\TestCase` (which extends Orchestra's `TestCase`).
- `withoutExceptionHandling()` is called in `setUp()` by default.
- Routes are registered inline per-test and must explicitly include `->middleware(Middleware::class)`.
- Spec fixtures live in `tests/Fixtures/` and are loaded via `SPEC_PATH=./tests/Fixtures` (set in `phpunit.xml`).
- Test method names use `snake_case` prefixed with `test_` (e.g., `test_validates_request_body`).

### Spec fixtures
- Named like `FeatureName.v1.yml` or `FeatureName.v1.json`.
- Called in tests with `Spectator::using('FeatureName.v1.yml')`.
- A new fixture file is needed for each distinct schema scenario being tested.

### Validation exceptions
- `RequestValidationException` and `ResponseValidationException` extend `SchemaValidationException`.
- Validators call `static::withError($message, $result->error())` to construct exceptions with structured error info.
- Assertions check exception type via `expectsTrue`/`expectsFalse` (in `HasExpectations` trait).

### Spec caching
`RequestFactory` caches parsed specs in a static `$cachedSpecs` array keyed by file path. If you change a fixture file during a test run, the cache won't be invalidated.

### Path prefix
When the API is mounted under a prefix (e.g., `/v1`), set `spectator.path_prefix` in config or call `Spectator::setPathPrefix('v1')`. The middleware strips the prefix before matching against spec paths.

### OpenAPI version handling
The middleware sets `$version` from `$openapi->openapi` after resolving the spec. This version string is passed to validators to handle 3.0 vs 3.1 differences (primarily `nullable` semantics).
