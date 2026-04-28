<p align="center"><img src="./art/spectator-logo.png" width="300"></p>

# Spectator

Spectator provides light-weight OpenAPI contract testing tools that work within your existing Laravel test suite.

Write tests that guarantee your API spec never drifts from your implementation.

![Tests](https://github.com/hotmeteor/spectator/workflows/Tests/badge.svg)
[![Latest Version on Packagist](https://img.shields.io/packagist/vpre/hotmeteor/spectator.svg?style=flat-square)](https://packagist.org/packages/hotmeteor/spectator)
![PHP from Packagist](https://img.shields.io/packagist/php-v/hotmeteor/spectator)

---

## What's New in v3

- **PHP 8.3+ and Laravel 12+** — minimum requirements raised to track the modern PHP ecosystem.
- **New artisan commands** — `spectator:validate` lints your spec file; `spectator:coverage` lists every operation defined in the spec; `spectator:routes` cross-references spec operations against Laravel routes; `spectator:stubs` generates skeleton test classes from a spec. All commands support `--format=json` for machine-readable output.
- **PHPUnit coverage extension** — `SpectatorExtension` tracks which spec operations are exercised during a test run and can enforce a minimum coverage threshold in CI.
- **Machine-readable JSON errors** — set `SPECTATOR_ERROR_FORMAT=json` (or call `Spectator::useJsonErrors()`) to get structured `{"errors": [...]}` output from failed assertions instead of ANSI-coloured text.
- **Modern PHP internals** — enums replace string/class constants; first-class callables, `readonly` properties, and `match` expressions throughout.
- **Remote & GitHub spec sources verified** — remote HTTP and private GitHub spec fetching work reliably out of the box.
- **Fluent path-prefix API** — `Spectator::withPathPrefix('v1')` as an alternative to the config key.

---

## Requirements

- PHP 8.3+
- Laravel 12+

---

## Installation

```bash
composer require hotmeteor/spectator --dev
```

Publish the config file:

```bash
php artisan vendor:publish --provider="Spectator\SpectatorServiceProvider"
```

---

## Configuration

The published config lives at `config/spectator.php`. The most important setting is the spec **source**, which tells Spectator where to find your OpenAPI spec files.

### Local

Specs are read from the local filesystem.

```env
SPEC_SOURCE=local
SPEC_PATH=/path/to/specs
```

### Remote

Specs are fetched over HTTP. Useful for remote-hosted specs or raw GitHub file URLs.

```env
SPEC_SOURCE=remote
SPEC_PATH=https://raw.githubusercontent.com/org/repo/main/specs
SPEC_URL_PARAMS="?token=abc123"   # optional query params appended to the URL
```

### GitHub

Specs are fetched from a private GitHub repository using a Personal Access Token.

```env
SPEC_SOURCE=github
SPEC_GITHUB_REPO=org/repo
SPEC_GITHUB_PATH=main/specs       # branch + path to the directory
SPEC_GITHUB_TOKEN=ghp_yourtoken
```

### Path Prefix

If your API is mounted under a prefix (e.g. `/v1`), configure it here so Spectator strips it before matching spec paths.

```env
SPECTATOR_PATH_PREFIX=v1
```

Or set it at runtime:

```php
Spectator::withPathPrefix('v1');
```

### Error Format

By default, validation errors are rendered as human-readable, coloured terminal output. For CI pipelines and LLM toolchains that parse test output programmatically, switch to JSON:

```env
SPECTATOR_ERROR_FORMAT=json
```

Or toggle it per test:

```php
Spectator::useJsonErrors();   // emit {"errors": [...]}
Spectator::useTextErrors();   // revert to coloured text
```

---

## Writing Contract Tests

### What contract testing is

**Functional tests** verify that your application behaves correctly — validation passes, controllers respond, events fire.

**Contract tests** verify that your requests and responses conform to your OpenAPI spec. The data doesn't have to be real; the shape does.

The two test types complement each other. Keep them in separate test classes.

### Pointing to a spec

Call `Spectator::using()` with the spec filename before making any requests. You can call it once in `setUp()` or per test.

```php
use Spectator\Spectator;

class UserApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Spectator::using('Api.v1.yml');
    }

    #[Test]
    public function test_using_different_spec(): void
    {
        Spectator::using('OtherApi.v1.yml');
        // ...
    }
}
```

### Making assertions

Spectator adds these methods to Laravel's `TestResponse`:

| Method | Description |
|---|---|
| `assertValidRequest()` | Assert the request matches the spec. |
| `assertInvalidRequest()` | Assert the request does **not** match the spec. |
| `assertValidResponse(?int $status)` | Assert the response matches the spec (optionally at a specific status code). |
| `assertInvalidResponse(?int $status)` | Assert the response does **not** match the spec. |
| `assertValidationMessage(string $message)` | Assert the validation error message contains the given string. |
| `assertErrorsContain(string\|array $errors)` | Assert one or more strings appear in the validation errors. |
| `assertPathExists()` | Assert the requested path exists in the spec. |
| `dumpSpecErrors()` | Dump current spec errors without failing (useful for debugging). |

### A typical contract test

```php
use Spectator\Spectator;

class UserApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Spectator::using('Api.v1.yml');
    }

    #[Test]
    public function test_create_user(): void
    {
        $this->postJson('/users', ['name' => 'Alice', 'email' => 'alice@example.com'])
            ->assertValidRequest()
            ->assertValidResponse(201);
    }

    #[Test]
    public function test_missing_required_field_is_invalid(): void
    {
        $this->postJson('/users', ['name' => 'Alice'])   // missing email
            ->assertInvalidRequest()
            ->assertValidationMessage('required');
    }
}
```

### Mixing with functional tests

You can chain Spectator assertions with Laravel's built-in assertions, but keeping concerns separate is cleaner:

```php
// Works, but mixes concerns
$this->actingAs($user)
    ->postJson('/posts', ['title' => 'Hello'])
    ->assertCreated()
    ->assertValidRequest()
    ->assertValidResponse(201);
```

### Deactivating Spectator for a test

```php
Spectator::reset();
```

### Debugging errors

When a validation fails, Spectator renders the schema with errors annotated inline:

```
---

The properties must match schema: data

object++ <== The properties must match schema: data
    status*: string
    data*: array
        object <== The required properties (name) are missing
            id*: string
            name*: string
            email: string?

---
```

Symbol legend:
- `++` — object allows `additionalProperties`
- `*` — property is `required`
- `?` — property is `nullable`

Use `dumpSpecErrors()` to inspect errors without failing the test:

```php
$this->postJson('/users', $payload)
    ->dumpSpecErrors()
    ->assertValidRequest();
```

---

## Artisan Commands

### `spectator:validate`

Validate that a spec file parses without errors. Useful as a pre-test lint gate in CI.

```bash
php artisan spectator:validate --spec=Api.v1.yml
php artisan spectator:validate --spec=Api.v1.yml --format=json
```

Text output:

```
✔ Api.v1.yml is valid.
```

JSON output (`--format=json`):

```json
{
    "valid": true,
    "spec": "Api.v1.yml",
    "errors": []
}
```

Returns exit code `0` on success, `1` on failure.

### `spectator:coverage`

List every operation defined in the spec. Useful for auditing coverage gaps.

```bash
php artisan spectator:coverage --spec=Api.v1.yml
php artisan spectator:coverage --spec=Api.v1.yml --format=json
```

Text output:

```
Operations in Api.v1.yml:

 ────── ─────────────── 
  GET    /users
  POST   /users
  GET    /users/{id}
 ────── ─────────────── 

3 operations
```

JSON output (`--format=json`):

```json
{
    "spec": "Api.v1.yml",
    "operations": [
        { "method": "GET", "path": "/users" },
        { "method": "POST", "path": "/users" },
        { "method": "GET", "path": "/users/{id}" }
    ]
}
```

### `spectator:routes`

Cross-references spec operations against registered Laravel routes. Surfaces which operations are matched, which are missing from the app, and which routes have no spec entry.

```bash
php artisan spectator:routes --spec=Api.v1.yml
php artisan spectator:routes --spec=Api.v1.yml --format=json
```

Text output:

```
Routes in Api.v1.yml:

 ──────── ──────── ─────────────────── 
  Status   Method   Path
 ──────── ──────── ─────────────────── 
  ✔        GET      /users
  ✔        POST     /users
  ✗        DELETE   /users/{id}
  ⚠        GET      /internal
 ──────── ──────── ─────────────────── 

Matched: 2  |  Unimplemented: 1  |  Undocumented: 1
```

- `✔ matched` — in spec and a Laravel route exists
- `✗ unimplemented` — in spec, no matching Laravel route
- `⚠ undocumented` — Laravel route exists, not in spec

### `spectator:stubs`

Generates skeleton test classes from a spec. Groups operations by tag (fallback: first path segment) and creates one class per group with one `test_` method per operation. Each method body calls `$this->markTestIncomplete(...)` so the generated file is immediately runnable.

```bash
php artisan spectator:stubs --spec=Api.v1.yml
php artisan spectator:stubs --spec=Api.v1.yml --output=tests/Contract --namespace="Tests\\Contract"
php artisan spectator:stubs --spec=Api.v1.yml --force
```

| Option | Default | Description |
|---|---|---|
| `--spec` | — | Spec filename (required). |
| `--output` | `tests/Contract` | Directory to write generated classes to. |
| `--namespace` | `Tests\Contract` | PHP namespace for generated classes. |
| `--base-class` | `Tests\TestCase` | Parent class for generated test classes. |
| `--force` | `false` | Overwrite existing files. |

Example generated class:

```php
namespace Tests\Contract;

use Spectator\Spectator;
use Tests\TestCase;

class UsersContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Spectator::using('Api.v1.yml');
    }

    public function test_get_users(): void
    {
        $this->markTestIncomplete('Implement: GET /users');
    }

    public function test_post_users(): void
    {
        $this->markTestIncomplete('Implement: POST /users');
    }
}
```

---

## CI & AI Integration

### Validating specs in CI

Add `spectator:validate` as an early CI step to catch malformed specs before tests run:

```yaml
# GitHub Actions example
- name: Validate OpenAPI spec
  run: php artisan spectator:validate --spec=Api.v1.yml --format=json
```

### Machine-readable error output

Set `SPECTATOR_ERROR_FORMAT=json` in your CI environment to make validation errors parseable by log aggregators and LLM agents:

```env
SPECTATOR_ERROR_FORMAT=json
```

With this setting, a failed assertion produces a JSON error body instead of ANSI-coloured text:

```json
{
    "errors": [
        "The data (null) must match the type: string"
    ]
}
```

### Feeding errors to an LLM

The JSON error format is designed for toolchains that analyse test output programmatically. Parse `{"errors": [...]}` from test output and pass it directly to your LLM workflow for root-cause analysis or spec repair suggestions.

### Contract coverage tracking

`SpectatorExtension` is a PHPUnit 11 extension that tracks which spec operations are exercised during a test run and prints a coverage summary when the suite finishes.

Enable it in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Spectator\Coverage\SpectatorExtension">
        <!-- Fail the suite if coverage drops below 80% -->
        <parameter name="min_coverage" value="80"/>
        <!-- Optional: json | text (default: text) -->
        <parameter name="format" value="text"/>
    </bootstrap>
</extensions>
```

Example output at suite end:

```
Spectator Coverage
──────────────────────────────────────────
 Spec          Operations   Covered   %
──────────────────────────────────────────
 Api.v1.yml    6            5         83%
──────────────────────────────────────────
```

When `min_coverage` is set and not met, the extension causes PHPUnit to exit with code `1`, failing the CI job.

---

## Upgrading

Please read [UPGRADE.md](UPGRADE.md) for a full list of breaking changes between versions.

---

## Core Concepts

Spectator registers a middleware that intercepts every test request, matches it against the loaded spec's `PathItem`, and validates both the request and the response. Captured exceptions are stored on the `RequestFactory` singleton so assertions can read them after the response is returned.

### Key dependencies

- [`cebe/php-openapi`](https://github.com/cebe/php-openapi) — parses OpenAPI 3.x specs into typed objects
- [`opis/json-schema`](https://github.com/opis/json-schema) — validates request/response data against JSON Schema

---

## Sponsors

A huge thanks to all our sponsors who help push Spectator development forward!

If you'd like to become a sponsor, please [see here for more information](https://github.com/sponsors/hotmeteor). 💪

## Credits

- Created by [Adam Campbell](https://github.com/hotmeteor)
- Maintained by [Bastien Philippe](https://github.com/bastien-phi), [Jarrod Parkes](https://github.com/jarrodparkes), and [Adam Campbell](https://github.com/hotmeteor)
- Inspired by [Laravel OpenAPI](https://github.com/mdwheele/laravel-openapi) package by [Dustin Wheeler](https://github.com/mdwheele)
- [All Contributors](../../contributors)

<a href="https://github.com/hotmeteor/spectator/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=hotmeteor/spectator"/>
</a>

Made with [contributors-img](https://contrib.rocks).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

