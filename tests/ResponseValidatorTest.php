<?php

namespace Spectator\Tests;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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

    public function test_validates_valid_json_response(): void
    {
        Route::get('/users', static function () {
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
            ->assertValidResponse();
    }

    public function test_validates_invalid_json_response(): void
    {
        Route::get('/users', static function () {
            return [
                [
                    'id' => 'invalid',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse()
            ->assertValidationMessage('All array items must match schema');

        Route::get('/users', static function () {
            return [
                [
                    'id' => 1,
                    'email' => 'invalid',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse()
            ->assertValidationMessage('All array items must match schema');
    }

    public function test_validates_valid_problem_json_response()
    {
        Route::get('/users', function () {
            return response()->json([
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ], 422, ['Content-Type' => 'application/problem+json']);
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertValidResponse(422);
    }

    public function test_validates_invalid_problem_json_response()
    {
        Route::get('/users', function () {
            return response()->json([
                [
                    'id' => 'invalid',
                ],
            ], 422, ['Content-Type' => 'application/problem+json']);
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse()
            ->assertValidationMessage('All array items must match schema');

        Route::get('/users', function () {
            return response()->json([
                [
                    'id' => 1,
                    'email' => 'invalid',
                ],
            ], 422, ['Content-Type' => 'application/problem+json']);
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse()
            ->assertValidationMessage('All array items must match schema');
    }

    public function test_validates_problem_json_response_using_components()
    {
        Spectator::using('Test.v1.json');

        $uuid = (string) Str::uuid();

        Route::get('/orders/{order}', static function ($order) use ($uuid) {
            if ($order !== $uuid) {
                abort(404);
            }

            return [
                'uuid' => $uuid,
            ];
        })->middleware(Middleware::class);

        $response = $this->getJson("/orders/{$uuid}")
            ->assertValidResponse(200);

        $response = $this->getJson('/orders/invalid')
            ->assertValidResponse(404);
    }

    public function test_fallback_to_request_uri_if_operationId_not_given(): void
    {
        Spectator::using('Test.v1.json');

        Route::get('/path-without-operationId', static function () {
            return [
                'int' => 'not an int',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/path-without-operationId')
            ->assertValidRequest()
            ->assertInvalidResponse();
    }

    public function test_cannot_locate_path_without_path_prefix(): void
    {
        Spectator::using('Test.v2.json');

        Route::get('/api/v2/users', static function () {
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
            ->assertValidResponse();
    }

    public function test_uncaught_exceptions_are_thrown_when_exception_handling_is_disabled(): void
    {
        Route::get('/users', static function () {
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
    ): void {
        Spectator::using("Nullable.$version.json");

        Route::get('/users/{user}', static function () use ($state) {
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

    public function nullableProvider(): array
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
    public function test_handles_oneOf($response, $valid): void
    {
        Spectator::using('OneOf.v1.yml');

        Route::patch('/pets', static function () use ($response) {
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

    public function oneOfSchemaProvider(): array
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
    public function test_handles_anyOf($response, $isValid): void
    {
        Spectator::using('AnyOf.v1.yml');

        Route::patch('/pets', static function () use ($response) {
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

    public function anyOfSchemaProvider(): array
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
    public function test_handles_allOf($response, $isValid): void
    {
        Spectator::using('AllOf.v1.yml');

        Route::patch('/pets', static function () use ($response) {
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

    public function allOfSchemaProvider(): array
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

    public function test_handles_invalid_spec(): void
    {
        Spectator::using('Malformed.v1.yaml');

        Route::get('/')->middleware(Middleware::class);

        $this->getJson('/')
            ->assertInvalidRequest()
            ->assertInvalidResponse()
            ->assertValidationMessage('The spec file is invalid. Please lint it using spectral (https://github.com/stoplightio/spectral) before trying again.');
    }

    // https://swagger.io/docs/specification/data-models/inheritance-and-polymorphism/
    public function test_handles_inheritance(): void
    {
        Spectator::using('Components.v1.json');

        Route::get('/item', static function () {
            return [
                'name' => 'Table',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/item')
            ->assertValidRequest()
            ->assertInvalidResponse();

        Route::get('/item', static function () {
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

    // https://www.loom.com/share/63191fee2b45421db266dcd012579cb3
    public function test_response_example(): void
    {
        Spectator::using('Test.v2.json');

        Route::get('/tags', static function () {
            return [
                'status' => 'success',
                'data' => [
                    [
                        'id' => '3fafec77-402b-35f9-b26a-bd6430da3a29',
                        'name' => 'Photography',
                        'slug' => 'photography',
                    ],
                    [
                        'id' => '3fafec77-402b-35f9-b26a-bd6430da3a29',
                        'name' => 'Marketing',
                        'slug' => null,
                    ],
                ],
                'tester' => 'tester',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/tags')
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_errors_contain(): void
    {
        Route::get('/users', static function () {
            return [
                [
                    'id' => 'invalid',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users')
            ->assertValidRequest()
            ->assertInvalidResponse()
            ->assertValidationMessage('All array items must match schema')
            ->assertErrorsContain([
                'All array items must match schema',
                'The properties must match schema: id',
                'The data (string) must match the type: number',
            ]);
    }

    public function test_response_succeeds_with_empty_array(): void
    {
        Spectator::using('Arrays.v1.yaml');

        $uuid = (string) Str::uuid();

        Route::get('/orgs/{orgUuid}', static function () use ($uuid) {
            return [
                'id' => $uuid,
                'name' => 'My Org',
                'orders' => [],
            ];
        })->middleware(Middleware::class);

        $this->getJson("/orgs/$uuid")
            ->assertValidRequest()
            ->assertValidResponse();
    }

    public function test_response_fails_with_invalid_array(): void
    {
        Spectator::using('Arrays.v1.yaml');

        $uuid = (string) Str::uuid();

        Route::get('/orgs/{orgUuid}', static function () use ($uuid) {
            return [
                'id' => $uuid,
                'name' => 'My Org',
                'orders' => [[]],
            ];
        })->middleware(Middleware::class);

        $this->getJson("/orgs/$uuid")
            ->assertValidRequest()
            ->assertInvalidResponse()
            ->assertErrorsContain([
                'The properties must match schema: orders',
                'All array items must match schema',
                'The data (array) must match the type: object',
            ]);
    }

    public function test_array_any_of(): void
    {
        Spectator::using('ArrayAnyOf.v1.yml');

        Route::get('/pets', static function () {
            return [
                'data' => [
                    // PetByAge
                    ['age' => 5, 'nickname' => 'nick'],
                    // PetByType
                    ['pet_type' => 'Dog', 'hunts' => false],
                ],
            ];
        })->middleware(Middleware::class);

        $response = $this->getJson('/pets');
        $response->assertValidRequest()->assertValidResponse();
    }

    /**
     * @dataProvider requiredWriteOnlySchemaProvider
     */
    public function test_required_writeonly(
        $payload,
        $is_valid
    ): void {
        Spectator::using('RequiredWriteOnly.v1.yml');

        Route::get('/users', static function () use ($payload) {
            return $payload;
        })->middleware(Middleware::class);

        if ($is_valid) {
            $this->getJson('/users')
                ->assertValidResponse();
        } else {
            $this->getJson('/users')
                ->assertInvalidResponse();
        }
    }

    public function requiredWriteOnlySchemaProvider(): array
    {
        $valid = true;
        $invalid = false;

        return [
            'valid, Writeonly not passed' => [
                [
                    'id' => 1,
                    'email' => 'adam@hotmeteor.com',
                    'arrayProperty' => [
                        [
                            'id' => 2,
                        ],
                    ],
                    'anyOfProperty' => [
                        'id' => 2,
                    ],
                    'allOfProperty' => [
                        'id' => 2,
                    ],
                    'oneOfProperty' => [
                        'id' => 2,
                    ],
                ],
                $valid,
            ],
            'Invalid, Books not passed' => [
                [
                    'id' => 1,
                    'email' => 'adam@hotmeteor.com',
                ],
                $invalid,
            ],
            'invalid, Writeonly passed' => [
                [
                    'id' => 1,
                    'name' => 'Adam Campbell',
                    'email' => 'adam@hotmeteor.com',
                ],
                $invalid,
            ],
            'invalid, required not passed' => [
                [
                    'name' => 'Adam Campbell',
                    'email' => 'adam@hotmeteor.com',
                ],
                $invalid,
            ],
        ];
    }
}
