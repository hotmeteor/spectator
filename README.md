# Spectator
Spectator provides light-weight OpenAPI testing tools you can use within your existing Laravel test suite.

![Tests](https://github.com/hotmeteor/spectator/workflows/Tests/badge.svg)

## Installation

You can install the package through Composer.

```bash
composer require hotmeteor/spectator
```

Then, publish the config file of this package with this command:

```bash
php artisan vendor:public --provider="Spectator\ServiceProvider"
```

The config file will be published in `config/spectator.php`.

### Sources

**Sources** are references to where your API spec lives. Depending on the way you or your team works, or where your
 spec lives, you may want to configure different sources for different environments. 

As you can see from the config, there's three source types available: `local`, `remote`, and `github`. Each source
 requires the folder where your spec lives to be defined, not the spec file itself. This provides flexibility when
  working with multiple APIs in one project, or an API fragmented across multiple spec files.

## Testing

### Paradigm Shift

**Now, on to the good stuff.**

At first, spec testing, or contract testing, may seem counter-intuitive, especially when compared with "feature" or
 "functional" testing as supported by Laravel's [HTTP Tests](https://laravel.com/docs/7.x/http-tests). While
  functional tests are ensuring that your request validation, controller behavior, events, responses, etc. all behave
   the way you expect when people interact with your API, contract tests are ensuring that **requests and responses
    are spec-compliant**, and that's it. 
    
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
            ->assertValidResponse();
    }
}
```
The contract testing is **not** verifying a correct response code, since there could be a variety of responses that
 come back, based on the conditions of the request. What it is testing is that both the request and the response is
  valid according to the spec, in this case located in `Api.v1.json`.
  
Within your spec, each possible response should be documented. For example, a single `POST` endpoint may result in a
 `2xx`, `4xx`, or even `5xx` code response. Additionally, your endpoints will likely have particular parameter
  validation that needs to be adhered to. This is what makes contract testing different from functional testing: in
   functional testing, successful and failed responses are tested for outcomes; in contract testing, requests and
    responses are tested for conformity and outcomes don't matter. 
  
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
$this->assertValidResponse();
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
  
## Credits

- [Adam Campbell](https://github.com/hotmeteor)
- Inspiration and borrowed code from the [Laravel OpenAPI
](https://github.com/mdwheele/laravel-openapi) package by [Dustin Wheeler](https://github.com/mdwheele)
- [All Contributors](../../contributors)


## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
