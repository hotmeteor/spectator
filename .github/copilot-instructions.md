# Spectator — Copilot Instructions

Spectator is a Laravel package that provides OpenAPI contract testing tools. It validates that HTTP requests and responses conform to an OpenAPI spec, complementing (not replacing) Laravel's functional tests.

**Requirements: PHP 8.3+, Laravel 12+**

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
2. **`RequestFactory`** — holds the active spec name, path prefix, and captured exceptions (`requestException`, `responseException`). Parses and caches spec files. Uses the `Macroable` trait for extensibility. Key methods: `using()`, `reset()`, `withPathPrefix()` / `setPathPrefix()`, `useJsonErrors()` / `useTextErrors()`.
3. **`Middleware`** — prepended to the `api` middleware group. On each request it resolves the spec, matches the route to a `PathItem`, then calls `RequestValidator` and `ResponseValidator`. Exceptions from validators are *captured* into `RequestFactory` (not thrown), allowing assertions to run after the response is returned.
4. **`RequestValidator` / `ResponseValidator`** — both extend `AbstractValidator`, which handles:
   - OpenAPI 3.0 → 3.1 `nullable` migration (converts `nullable: true` to `type: [T, null]` internally)
   - `readOnly`/`writeOnly` property filtering based on read/write mode
   - `runValidation()` is defined on `AbstractValidator` and called by both subclasses
5. **`Assertions`** — mixed into `TestResponse` via `TestResponse::mixin(new Assertions)`. Each method returns a `Closure` (the mixin pattern). Assertions read the captured exceptions from `app('spectator')`.
6. **`SpectatorServiceProvider`** — registers the singleton, merges config, but only registers middleware and the `TestResponse` mixin when `App::runningInConsole()` (i.e., during tests). Also registers artisan commands inside this guard.
7. **`Console/ValidateSpecCommand`** — `spectator:validate --spec=<file> [--format=json]`. Validates that a spec file parses without errors.
8. **`Console/CoverageCommand`** — `spectator:coverage --spec=<file> [--format=json]`. Lists every operation (method + path) defined in the spec.
9. **`Console/RoutesCommand`** — `spectator:routes --spec=<file> [--format=json]`. Cross-references spec operations against registered Laravel routes (matched / unimplemented / undocumented). Uses `Route::getRoutes()->getRoutes()` for PHPStan-safe iteration.
10. **`Console/StubsCommand`** — `spectator:stubs --spec=<file> [--output] [--namespace] [--base-class] [--force]`. Generates skeleton test classes from a spec. **Path handling note:** uses `str_starts_with($output, DIRECTORY_SEPARATOR)` to detect absolute paths and use them as-is; otherwise resolves relative to `base_path()`.
11. **`Coverage/CoverageTracker`** — static process-level registry (`array<string, true>` set semantics) that records which spec operations are exercised. `recordSpec()` is idempotent (guarded with `isset()`). Called from `Middleware`: `recordSpec()` in `pathItem()`, `record()` in `validate()` before validators run.
12. **`Coverage/SpectatorExtension`** — PHPUnit 11 `Extension` implementation. Subscribes to `ExecutionFinished` via anonymous class. Reads `CoverageTracker::getBySpec()`, prints coverage table. `min_coverage` parameter uses `register_shutdown_function(fn () => exit(1))` to override PHPUnit's exit code for CI failure (direct exit from `ExecutionFinished` subscriber is not supported by PHPUnit).

## Key Conventions

### Test structure
- Tests extend `Spectator\Tests\TestCase` (which extends Orchestra's `TestCase`).
- `withoutExceptionHandling()` is called in `setUp()` by default.
- Routes are registered inline per-test and must explicitly include `->middleware(Middleware::class)`.
- Spec fixtures live in `tests/Fixtures/` and are loaded via `SPEC_PATH=./tests/Fixtures` (set in `phpunit.xml`).
- Test method names use `snake_case` prefixed with `test_` (e.g., `test_validates_request_body`).

### Console command tests
- Use Laravel's `$this->artisan(...)` with `expectsOutputToContain()` — one assertion per `artisan()` chain.
- **Do not** use `expectsOutput()` with multi-line strings; it matches a single `doWrite` call and will fail.
- JSON output uses `foreach (explode(PHP_EOL, json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) { $this->line($line); }` so each line is a separate `doWrite` call.
- Command helper methods must not be named `fail()` — `Illuminate\Console\Command` already declares `fail()`.
- `RoutesCommand` and `StubsCommand` tests register inline routes with `Route::get(...)` inside the test method before calling `$this->artisan(...)`.
- `StubsCommand` tests use `sys_get_temp_dir().'/spectator-stubs-test-'.uniqid()` as `--output` (absolute path). The command detects absolute paths via `str_starts_with($output, DIRECTORY_SEPARATOR)` and uses them as-is.

### Coverage tracking
- `CoverageTracker` is a **process-level static registry** — data accumulates across all test methods in a PHPUnit run. Never call `CoverageTracker::reset()` in `tearDown()`; only call it in unit tests that need isolation or at suite start.
- The tracker uses `array<string, true>` set semantics (not append-only arrays) to prevent double-counting. Keys are `"METHOD /path"` strings.
- `SpectatorExtension` subscribes to `ExecutionFinished` (not `RunFinished`). To fail the PHPUnit process when `min_coverage` is not met, use `register_shutdown_function(static fn () => exit(1))` — this overrides PHPUnit's own exit code since PHPUnit calls `exit()` after all subscribers run.

### Spec fixtures
- Named like `FeatureName.v1.yml` or `FeatureName.v1.json`.
- Called in tests with `Spectator::using('FeatureName.v1.yml')`.
- A new fixture file is needed for each distinct schema scenario being tested.

### Validation exceptions
- `RequestValidationException` and `ResponseValidationException` extend `SchemaValidationException`.
- Validators call `static::withError($message, $result->error())` to construct exceptions with structured error info.
- `SchemaValidationException::validationErrorMessage()` checks `config('spectator.error_format')`: when `'json'`, returns `{"errors": [...]}` JSON; otherwise renders annotated ANSI text.
- Assertions check exception type via `expectsTrue`/`expectsFalse` (in `HasExpectations` trait).

### Enums (v3+)
Internal constants are now proper PHP enums:
- `SpecSource` — `Local`, `Remote`, `Github` (spec source types)
- `ValidationMode` — `Request`, `Response` (used in `AbstractValidator`)
- `Format` — `Text`, `Json` (console output format; string-backed, used by commands)

### Error format
`config('spectator.error_format')` defaults to `'text'`. Set to `'json'` via `SPECTATOR_ERROR_FORMAT=json` or `Spectator::useJsonErrors()`. `RequestFactory::reset()` does **not** reset this — it is config-driven and survives within a test. Orchestra Testbench provides a fresh app per test, so there's no cross-test leakage.

### Spec caching
`RequestFactory` caches parsed specs in a static `$cachedSpecs` array keyed by file path. If you change a fixture file during a test run, the cache won't be invalidated.

### Path prefix
When the API is mounted under a prefix (e.g., `/v1`), set `spectator.path_prefix` in config or call `Spectator::withPathPrefix('v1')` (fluent alias for `setPathPrefix()`). The middleware strips the prefix before matching against spec paths.

### OpenAPI version handling
The middleware sets `$version` from `$openapi->openapi` after resolving the spec. This version string is passed to validators to handle 3.0 vs 3.1 differences (primarily `nullable` semantics).
