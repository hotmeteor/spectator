<p align="center"><img src="https://spectator.s3.us-east-2.amazonaws.com/spectator-logo.png" width="300"></p>

# Spectator
Spectator provides light-weight OpenAPI testing tools you can use within your existing Laravel test suite.

Write tests that verify your API spec doesn't drift from your implementation.

![Tests](https://github.com/hotmeteor/spectator/workflows/Tests/badge.svg)
[![Latest Version on Packagist](https://img.shields.io/packagist/vpre/hotmeteor/spectator.svg?style=flat-square)](https://packagist.org/packages/hotmeteor/spectator)
![PHP from Packagist](https://img.shields.io/packagist/php-v/hotmeteor/spectator)

## Installation

You can install the package through Composer.

```bash
composer require hotmeteor/spectator --dev
```

Then, publish the config file of this package with this command:

```bash
php artisan vendor:publish --provider="Spectator\SpectatorServiceProvider"
```

The config file will be published in `config/spectator.php`.

### Sources

**Sources** are references to where your API spec lives. Depending on the way you or your team works, or where your spec lives, you may want to configure different sources for different environments. 

As you can see from the config, there's three source types available: `local`, `remote`, and `github`. Each source requires the folder where your spec lives to be defined, not the spec file itself. This provides flexibility when working with multiple APIs in one project, or an API fragmented across multiple spec files.

---
#### Local Example

```env
## Spectator config

SPEC_SOURCE=local
SPEC_PATH=/spec/reference
```
---
#### Remote Example
_This is using the raw access link from Github, but any remote source can be specified.  The SPUR_URL_PARAMS can be used to append any additional parameters required for the remote url._
```env
## Spectator config

SPEC_PATH="https://raw.githubusercontent.com/path/to/repo"
SPUR_URL_PARAMS="?token=ABEDC3E5AQ3HMUBPPCDTTMDAFPMSM"
```
---
#### Github Example
_This uses the Github Personal Access Token which allows you access to a remote repo containing your contract._

You can view instructions on how to obtain your Personal Access Token from Github at [this link](https://docs.github.com/en/github/authenticating-to-github/creating-a-personal-access-token) .

**Important to note than the SPEC_GITHUB_PATH must included the branch (ex: main) and then the path to the directory containing your contract.**


```env
## Spectator config

SPEC_GITHUB_PATH='main/contracts' 
SPEC_GITHUB_REPO='orgOruser/repo'
SPEC_GITHUB_TOKEN='your personal access token'
```
---
#### Specifying Your File In Your Tests
In your tests you will declare the spec file you want to test against:
```php
public function testBasicExample()
{
    Spectator::using('Api.v1.json');
    
    // ...
```

## Testing

### Paradigm Shift

**Now, on to the good stuff.**

At first, spec testing, or contract testing, may seem counter-intuitive, especially when compared with "feature" or "functional" testing as supported by Laravel's [HTTP Tests](https://laravel.com/docs/8.x/http-tests). While functional tests are ensuring that your request validation, controller behavior, events, responses, etc. all behave the way you expect when people interact with your API, contract tests are ensuring that **requests and responses are spec-compliant**, and that's it. 
    
### Writing Tests

Spectator adds a few simple tools to the existing Laravel testing toolbox.

Here's an example of a typical JSON API test:
```php
<?php

class ExampleTest extends TestCase
{
    /**
     * A basic functional test example.
     *
     * @return void
     */
    public function testBasicExample()
    {
        $response = $this->postJson('/user', ['name' => 'Sally']);

        $response
            ->assertStatus(201)
            ->assertJson([
                'created' => true,
            ]);
    }
}
```
And here's an example of a contract test:
```php
<?php

use Spectator/Spectator;

class ExampleTest extends TestCase
{
    /**
     * A basic functional test example.
     *
     * @return void
     */
    public function testBasicExample()
    {
        Spectator::using('Api.v1.json');

        $response = $this->postJson('/user', ['name' => 'Sally']);

        $response
            ->assertValidRequest()
            ->assertValidResponse(201);
    }
}
```
The test is verifying that both the request and the response are valid according to the spec, in this case located in `Api.v1.json`. This type of testing promotes TDD: you can write endpoint contract tests against your endpoints _first_, and then ensure your spec and implementation are aligned.
  
Within your spec, each possible response should be documented. For example, a single `POST` endpoint may result in a `2xx`, `4xx`, or even `5xx` code response. Additionally, your endpoints will likely have particular parameter validation that needs to be adhered to. This is what makes contract testing different from functional testing: in functional testing, successful and failed responses are tested for outcomes; in contract testing, requests and responses are tested for conformity and outcomes don't matter. 
  
## Usage

Define the spec file to test against. This can be defined in your `setUp()` method or in a specific test method.
```php
<?php

use Spectator/Spectator;

class ExampleTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();        

        Spectator::using('Api.v1.json');
    }

    public function testApiEndpoint()
    {        
        // Test request and response...
    }

    public function testDifferentApiEndpoint()
    {
        Spectator::using('Other.v1.json');
        
        // Test request and response...
    }
}
```

When testing endpoints, there are a few new methods:
```php
$this->assertValidRequest();
$this->assertValidResponse($status = null);
$this->assertValidationMessage('Expected validation message');
$this->assertErrorsContain('Check for single error');
$this->assertErrorsContain(['Check for', 'Multiple Errors']);
```
Of course, you can continue to use all existing HTTP test methods:
```php
$this
    ->actingAs($user)
    ->postJson('/comments', [
        'message' => 'Just over here spectating',
    ])
    ->assertCreated()
    ->assertValidRequest()
    ->assertValidResponse();
```
That said, mixing functional and contract testing may become more difficult to manage and read later.

Instead of using the built-in `->assertStatus($status)` method, you may also verify the response that is valid is actually the response you want to check. For example, you may receive a `200` **or** a `202` from a single endpoint, and you want to ensure you're validating the correct response.
```php
$this
    ->actingAs($user)
    ->postJson('/comments', [
        'message' => 'Just over here spectating',
    ])
    ->assertValidRequest()
    ->assertValidResponse(201);
```

When exceptions are thrown that are not specific to this package's purpose, e.g. typos or missing imports, the output will be formatted by default with a rather short message and no stack trace.
This can be changed by disabling Laravel's built-in validation handler which allows for easier debugging when running tests.

This can be done in a few different ways:

```php
class ExampleTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Spectator::using('Api.v1.json');

        // Disable exception handling for all tests in this file
        $this->withoutExceptionHandling();
    }

    // ...
}
```

```php
class ExampleTestCase
{
    public function test_some_contract_test_example(): void
    {
        // Only disable exception handling for this test
        $this->withouthExceptionHandling();

        // Test request and response ...

    }
}
```

## Credits

- [Adam Campbell](https://github.com/hotmeteor)
- Inspired by [Laravel OpenAPI](https://github.com/mdwheele/laravel-openapi) package by [Dustin Wheeler](https://github.com/mdwheele)
- [All Contributors](../../contributors)


## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
