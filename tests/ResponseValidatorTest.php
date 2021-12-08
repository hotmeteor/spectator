<?php

namespace Spectator\Tests;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Spectator\Middleware;
use Spectator\Spectator;
use Spectator\SpectatorServiceProvider;

class ResponseValidatorTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->register(SpectatorServiceProvider::class);

        Spectator::using('Test.v1.json');
    }

    // TODO: TEMP: REMOVE!
    public function testOneOffJarrod()
    {
        Spectator::using('Test.v2.json');

        Route::get('/tags', function () {
            return [
                'status' => "success",
                'data' => [
                    [
                        'id' => '3fafec77-402b-35f9-b26a-bd6430da3a29',
                        'name' => 'Photography',
                        'slug' => 'photography'
                    ],
                    [
                        'id' => '3fafec77-402b-35f9-b26a-bd6430da3a29',
                        'name' => 'Marketing',
                        'slug' => 'null',
                        'tester' => 'tester'
                    ]
                ],
                'tester' => 'tester'
            ];
        })->middleware(Middleware::class);

        $this->getJson('/tags')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_validates_valid_json_response()
    {
        Route::get('/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_validates_invalid_json_response()
    {
        Route::get('/users', function () {
            return [
                [
                    'id' => 'invalid',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('All array items must match schema');

        Route::get('/users', function () {
            return [
                [
                    'id' => 1,
                    'email' => 'invalid',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('All array items must match schema');
    }

    public function test_fallback_to_request_uri_if_operationId_not_given()
    {
        Spectator::using('Test.v1.json');

        Route::get('/path-without-operationId', function () {
            return [
                'int' => 'not an int',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/path-without-operationId')
            ->assertValidRequest()
            ->assertInvalidResponse(400)
            ->assertValidationMessage('The properties must match schema: {properties}');
    }

    public function test_cannot_locate_path_without_path_prefix()
    {
        Spectator::using('Test.v2.json');

        Route::get('/api/v2/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/api/v2/users')
            ->assertInvalidRequest()
            ->assertValidationMessage('Path [GET /api/v2/users] not found in spec.');

        Config::set('spectator.path_prefix', '/api/v2/');

        $this->getJson('/api/v2/users')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_uncaught_exceptions_are_thrown_when_exception_handling_is_disabled(): void
    {
        Route::get('/users', function () {
            throw new Exception('Something went wrong in the codebase!');
        })->middleware(Middleware::class);

        try {
            $this->withoutExceptionHandling()->getJson('/users');
        } catch (Exception $e) {
            $this->assertEquals('Something went wrong in the codebase!', $e->getMessage());

            return;
        }

        $this->fail('Failed asserting an exception was thrown');
    }

    /**
     * @dataProvider nullableProvider
     */
    public function test_handle_nullables(
        $version,
        $state,
        $is_valid
    ) {
        Spectator::using("Nullable.{$version}.json");

        Route::get('/users/{user}', function () use ($state) {
            $return = [
                'first_name' => 'Joe',
                'last_name' => 'Bloggs',
                'posts' => [
                    ['title' => 'My first post!'],
                ],
            ];

            if ($state === self::NULLABLE_EMPTY_STRING) {
                $return['email'] = '';
                $return['settings']['last_updated_at'] = '';
                $return['settings']['notifications']['email'] = '';
                $return['posts'][0]['body'] = '';
            }

            if ($state === self::NULLABLE_VALID) {
                $return['email'] = 'test@test.com';
            }

            if ($state === self::NULLABLE_INVALID) {
                $return['email'] = [1, 2, 3];
                $return['settings']['last_updated_at'] = [1, 2, 3];
                $return['settings']['notifications']['email'] = [1, 2, 3];
                $return['posts'][0]['body'] = [1, 2, 3];
            }

            if ($state === self::NULLABLE_NULL) {
                $return['email'] = null;
                $return['settings']['last_updated_at'] = null;
                $return['settings']['notifications']['email'] = null;
                $return['posts'][0]['body'] = null;
            }

            return $return;
        })->middleware(Middleware::class);

        if ($is_valid) {
            $this->getJson('/users/1')
                ->assertValidRequest()
                ->assertValidResponse();
        } else {
            $this->getJson('/users/1')
                ->assertValidRequest()
                ->assertInvalidResponse();
        }
    }

    public function nullableProvider()
    {
        $validResponse = true;
        $invalidResponse = false;

        $v30 = '3.0';
        $v31 = '3.1';

        return [
            // OA 3.0.0
            '3.0, missing' => [
                $v30,
                self::NULLABLE_MISSING,
                $validResponse,
            ],

            '3.0, empty' => [
                $v30,
                self::NULLABLE_EMPTY_STRING,
                $validResponse,
            ],

            '3.0, null' => [
                $v30,
                self::NULLABLE_NULL,
                $validResponse,
            ],

            '3.0, valid' => [
                $v30,
                self::NULLABLE_VALID,
                $validResponse,
            ],

            '3.0, invalid' => [
                $v30,
                self::NULLABLE_INVALID,
                $invalidResponse,
            ],

            // OA 3.1.0
            '3.1, missing' => [
                $v31,
                self::NULLABLE_MISSING,
                $validResponse,
            ],

            '3.1, empty' => [
                $v31,
                self::NULLABLE_EMPTY_STRING,
                $validResponse,
            ],

            '3.1, null' => [
                $v31,
                self::NULLABLE_NULL,
                $validResponse,
            ],

            '3.1, valid' => [
                $v31,
                self::NULLABLE_VALID,
                $validResponse,
            ],

            '3.1, invalid' => [
                $v31,
                self::NULLABLE_INVALID,
                $invalidResponse,
            ],
        ];
    }

    /**
     * @dataProvider oneOfSchemaProvider
     */
    // https://swagger.io/docs/specification/data-models/oneof-anyof-allof-not/
    public function test_handles_oneOf($response, $valid)
    {
        Spectator::using('OneOf.v1.yml');

        Route::patch('/pets', function () use ($response) {
            return $response;
        })->middleware(Middleware::class);

        $request = [
            'bark' => true,
            'breed' => 'Dingo',
        ];

        if ($valid) {
            $this->patchJson('/pets', $request)
                ->assertValidResponse();
        } else {
            $this->patchJson('/pets', $request)
                ->assertInvalidResponse();
        }
    }

    public function oneOfSchemaProvider()
    {
        $valid = true;
        $invalid = false;

        return [
            'valid response, first type' => [
                [
                    'bark' => true,
                    'breed' => 'Dingo',
                ],
                $valid,
            ],
            'valid response, second type' => [
                [
                    'hunts' => true,
                    'age' => 2,
                ],
                $valid,
            ],
            'invalid response' => [
                [
                    'bark' => true,
                    'hunts' => false,
                ],
                $invalid,
            ],
            'invalid response, mixed' => [
                [
                    'bark' => true,
                    'hunts' => false,
                    'breed' => 'Husky',
                    'age' => 3,
                ],
                $invalid,
            ],
        ];
    }

    /**
     * @dataProvider anyOfSchemaProvider
     */
    // https://swagger.io/docs/specification/data-models/oneof-anyof-allof-not/
    public function test_handles_anyOf($response, $isValid)
    {
        Spectator::using('AnyOf.v1.yml');

        Route::patch('/pets', function () use ($response) {
            return $response;
        })->middleware(Middleware::class);

        $request = [
            'age' => 1,
        ];

        $handled_request = $this->patchJson('/pets', $request);

        if ($isValid) {
            $handled_request->assertValidResponse();
        } else {
            $handled_request->assertInvalidResponse();
        }
    }

    public function anyOfSchemaProvider()
    {
        $valid = true;
        $invalid = false;

        return [
            'valid, one required' => [
                [
                    'age' => 1,
                ],
                $valid,
            ],
            'valid, other required' => [
                [
                    'pet_type' => 'Cat',
                    'hunts' => true,
                ],
                $valid,
            ],
            'valid, both required' => [
                [
                    'nickname' => 'Fido',
                    'pet_type' => 'Dog',
                    'age' => 4,
                ],
                $valid,
            ],
            'invalid request, missing required' => [
                [
                    'nickname' => 'Mr. Paws',
                    'hunts' => false,
                ],
                $invalid,
            ],
        ];
    }

    /**
     * @dataProvider allOfSchemaProvider
     */
    // https://swagger.io/docs/specification/data-models/oneof-anyof-allof-not/
    public function test_handles_allOf($response, $isValid)
    {
        Spectator::using('AllOf.v1.yml');

        Route::patch('/pets', function () use ($response) {
            return $response;
        })->middleware(Middleware::class);

        $request = [
            'pet_type' => 'Cat',
            'age' => 3,
            'hunts' => true,
        ];

        $handled_request = $this->patchJson('/pets', $request);

        if ($isValid) {
            $handled_request->assertValidResponse();
        } else {
            $handled_request->assertInvalidResponse();
        }
    }

    public function allOfSchemaProvider()
    {
        $valid = true;
        $invalid = false;

        return [
            'valid, Cat' => [
                [
                    'pet_type' => 'Cat',
                    'age' => 3,
                    'hunts' => true,
                ],
                $valid,
            ],
            'valid, Dog' => [
                [
                    'pet_type' => 'Dog',
                    'bark' => true,
                ],
                $valid,
            ],
            'valid, Dog 2' => [
                [
                    'pet_type' => 'Dog',
                    'bark' => true,
                    'breed' => 'Dingo',
                ],
                $valid,
            ],
            'invalid request, missing required' => [
                [
                    'age' => 3,
                ],
                $invalid,
            ],
            'invalid request, invalid attribute' => [
                [
                    'age' => 3,
                    'bark' => true,
                ],
                $invalid,
            ],
        ];
    }

    public function test_handles_invalid_spec()
    {
        Spectator::using('Malformed.v1.yaml');

        Route::get('/')->middleware(Middleware::class);

        $this->getJson('/')
            ->assertInvalidRequest()
            ->assertInvalidResponse()
            ->assertValidationMessage('The spec file is invalid. Please lint it using spectral (https://github.com/stoplightio/spectral) before trying again.');
    }

    // https://swagger.io/docs/specification/data-models/inheritance-and-polymorphism/
    public function test_handles_inheritance()
    {
        Spectator::using('Components.v1.json');

        Route::get('/item', function () {
            return [
                'name' => 'Table',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/item')
            ->assertValidRequest()
            ->assertInvalidResponse();

        Route::get('/item', function () {
            return [
                'name' => 'Table',
                'type' => 1234,
                'description' => 'Furniture',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/item')
            ->assertValidRequest()
            ->assertValidResponse();
    }
}
