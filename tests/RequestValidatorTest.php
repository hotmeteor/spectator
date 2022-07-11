<?php

namespace Spectator\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spectator\Middleware;
use Spectator\Spectator;
use Spectator\SpectatorServiceProvider;

/**
 * This TestCase makes use of data providers. Follow the link for more details:
 * https://phpunit.readthedocs.io/en/stable/writing-tests-for-phpunit.html#data-providers.
 */
class RequestValidatorTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->register(SpectatorServiceProvider::class);
    }

    public function test_cannot_resolve_prefixed_path(): void
    {
        Spectator::using('Versioned.v1.json');

        Route::get('/v1/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/v1/users')
            ->assertInvalidRequest()
            ->assertValidationMessage('Path [GET /v1/users] not found in spec.');
    }

    public function test_resolves_prefixed_path_from_config(): void
    {
        Spectator::using('Versioned.v1.json');
        Config::set('spectator.path_prefix', 'v1');

        Route::get('/v1/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/v1/users')
            ->assertValidRequest();
    }

    public function test_uses_global_path_via_inline_setting(): void
    {
        Spectator::setPathPrefix('v1')->using('Versioned.v1.json');

        Route::get('/v1/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })->middleware(Middleware::class);

        $this->getJson('/v1/users')
            ->assertValidRequest();
    }

    public function test_uses_global_path_through_config(): void
    {
        Config::set('spectator.path_prefix', 'v1');

        Spectator::using('Global.v1.yaml');

        $uuid = (string) Str::uuid();

        Route::get('/v1/orgs/{orgUuid}', function () use ($uuid) {
            return [
                'id' => 1,
                'uuid' => $uuid,
                'name' => 'My Org',
            ];
        })->middleware(Middleware::class);

        $this->getJson("/v1/orgs/$uuid")
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_uses_global_path_with_route_prefix(): void
    {
        Config::set('spectator.path_prefix', 'v1');

        Spectator::using('Global.v1.yaml');

        $uuid = (string) Str::uuid();

        Route::prefix('v1')->group(function () use ($uuid) {
            Route::get('/orgs/{orgUuid}', function () use ($uuid) {
                return [
                    'id' => 1,
                    'uuid' => $uuid,
                    'name' => 'My Org',
                ];
            })->middleware(Middleware::class);
        });

        $this->getJson("/v1/orgs/$uuid")
            ->assertValidRequest()
            ->assertValidResponse(200);
    }

    public function test_resolve_route_model_binding(): void
    {
        Spectator::using('Test.v1.json');

        Route::get('/users/{user}', function () {
            return [
                'id' => 1,
                'name' => 'Jim',
                'email' => 'test@test.test',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/users/1')
            ->assertValidRequest();
    }

    public function test_resolve_route_model_explicit_binding(): void
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', TestUser::class);

        Route::get('/posts/{postUuid}', function () {
            return [
                'id' => 1,
                'title' => 'My Post',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/posts/'.Str::uuid()->toString())
            ->assertValidRequest();
    }

    public function test_cannot_resolve_route_model_explicit_binding_with_invalid_format(): void
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', TestUser::class);

        Route::get('/posts/{postUuid}', function () {
            return [
                'id' => 1,
                'title' => 'My Post',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/posts/invalid')
            ->assertInvalidRequest();
    }

    public function test_resolve_route_model_binding_with_multiple_parameters(): void
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', TestUser::class);

        Route::get('/posts/{postUuid}/comments/{comment}', function () {
            return [
                'id' => 1,
                'message' => 'My Comment',
            ];
        })->middleware(Middleware::class);

        $this->getJson('/posts/'.Str::uuid()->toString().'/comments/1')
            ->assertValidRequest();
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

        Route::post('/users')->middleware(Middleware::class);

        $payload = [
            'name' => 'Adam Campbell',
            'email' => 'adam@hotmeteor.com',
        ];

        if ($state === self::NULLABLE_MISSING) {
            // Well... it's missing.
        }

        if ($state === self::NULLABLE_EMPTY_STRING) {
            $payload['nickname'] = '';
        }

        if ($state === self::NULLABLE_VALID) {
            $payload['nickname'] = 'hotmeteor';
        }

        if ($state === self::NULLABLE_NULL) {
            $payload['nickname'] = null;
        }

        if ($is_valid) {
            $this->postJson('/users', $payload)
                ->assertValidRequest();
        } else {
            $this->postJson('/users', $payload)
                ->assertInvalidRequest();
        }
    }

    public function nullableProvider(): array
    {
        $validResponse = true;

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

        ];
    }

    /**
     * @dataProvider oneOfSchemaProvider
     */
    // https://swagger.io/docs/specification/data-models/oneof-anyof-allof-not/
    public function test_handles_oneOf($payload, $isValid): void
    {
        Spectator::using('OneOf.v1.yml');

        Route::patch('/pets', function () use ($payload) {
            return $payload;
        })->middleware(Middleware::class);

        $request = $this->patchJson('/pets', $payload);

        if ($isValid) {
            $request->assertValidRequest();
        } else {
            $request->assertInvalidRequest();
        }
    }

    public function oneOfSchemaProvider(): array
    {
        $valid = true;
        $invalid = false;

        return [
            'valid request, first type' => [
                [
                    'bark' => true,
                    'breed' => 'Dingo',
                ],
                $valid,
            ],
            'valid request, second type' => [
                [
                    'hunts' => true,
                    'age' => 2,
                ],
                $valid,
            ],
            'invalid request' => [
                [
                    'bark' => true,
                    'hunts' => false,
                ],
                $invalid,
            ],
            'invalid request, mixed' => [
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
    public function test_handles_anyOf($payload, $isValid): void
    {
        Spectator::using('AnyOf.v1.yml');

        Route::patch('/pets', function () use ($payload) {
            return $payload;
        })->middleware(Middleware::class);

        $request = $this->patchJson('/pets', $payload);

        if ($isValid) {
            $request->assertValidRequest();
        } else {
            $request->assertInvalidRequest();
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
    public function test_handles_allOf($payload, $isValid): void
    {
        Spectator::using('AllOf.v1.yml');

        Route::patch('/pets', function () use ($payload) {
            return $payload;
        })->middleware(Middleware::class);

        $request = $this->patchJson('/pets', $payload);

        if ($isValid) {
            $request->assertValidRequest();
        } else {
            $request->assertInvalidRequest();
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

    public function test_handles_query_parameters(): void
    {
        Spectator::using('Test.v1.json');

        // When testing query parameters, they are not found nor checked by RequestValidator->validateParameters().
        Route::get('/users', function () {
            return [];
        })->middleware(Middleware::class);

        $this->getJson('/users?order=invalid')
            ->assertInvalidRequest()
            ->assertErrorsContain([
                'The data should match one item from enum',
            ]);

        $this->getJson('/users?order=name')
            ->assertValidRequest();
    }

    public function test_handles_query_parameters_int(): void
    {
        Spectator::using('Test.v1.json');

        // When testing query parameters, they are not found nor checked by RequestValidator->validateParameters().
        Route::get('/users-by-id/{user}', function (int $user) {
            return [];
        })->middleware(Middleware::class);

        $this->getJson('/users-by-id/1')
            ->assertValidRequest();
    }

    public function test_handles_form_data(): void
    {
        Spectator::using('BinaryString.v1.json');

        Route::post('/users', function () {
            return [];
        })->middleware(Middleware::class);

        $this->post(
            '/users',
            ['name' => 'Adam Campbell', 'picture' => UploadedFile::fake()->image('test.jpg')],
            ['Content-Type' => 'multipart/form-data']
        )
            ->assertInvalidRequest()
            ->assertErrorsContain([
                'The required properties (email) are missing',
            ]);

        $this->post(
            '/users',
            ['name' => 'Adam Campbell', 'email' => 'test@test.com'],
            ['Content-Type' => 'multipart/form-data']
        )
            ->assertInvalidRequest()
            ->assertErrorsContain([
                'The required properties (picture) are missing',
            ]);

        $this->post(
            '/users',
            ['name' => 'Adam Campbell', 'email' => 'test@test.com', 'picture' => UploadedFile::fake()->image('test.jpg')],
            ['Content-Type' => 'multipart/form-data']
        )
            ->assertValidRequest();
    }

    public function test_handles_form_data_with_multiple_files(): void
    {
        Spectator::using('BinaryString.v1.json');

        Route::post('/users/multiple-files', function () {
            return [];
        })->middleware(Middleware::class);

        $this->post(
            '/users/multiple-files',
            [
                'picture' => UploadedFile::fake()->image('test.jpg'),
                'files' => [
                    ['name' => 'test.jpg', 'file' => UploadedFile::fake()->image('test.jpg')],
                    ['name' => 'test.jpg', 'file' => UploadedFile::fake()->image('test.jpg')],
                ],
                'resume' => [
                    'name' => 'test.pdf',
                    'file' => UploadedFile::fake()->create('test.pdf'),
                ],
            ],
            ['Content-Type' => 'multipart/form-data']
        )
            ->assertValidRequest();

        $this->withoutExceptionHandling()->post(
            '/users/multiple-files',
            [
                'picture' => UploadedFile::fake()->image('test.jpg'),
                'files' => [],
                'resume' => [
                    'name' => 'test.pdf',
                    'file' => UploadedFile::fake()->create('test.pdf'),
                ],
            ],
            ['Content-Type' => 'multipart/form-data']
        )
            ->assertValidRequest();
    }

    /**
     * @dataProvider nullableObjectProvider
     */
    public function test_nullable_object($payload, $isValid): void
    {
        Spectator::using('Nullable-Object.yml');

        Route::patch('/pets', static function () use ($payload) {
            return $payload;
        })->middleware(Middleware::class);

        $response = $this->patchJson('/pets', $payload);

        if ($isValid) {
            $response->assertValidRequest();
            $response->assertValidResponse(200);
        } else {
            $response->assertInvalidRequest();
            $response->assertInvalidResponse();
        }
    }

    public function nullableObjectProvider(): array
    {
        return [
            [['name' => 'dog', 'friend' => null], true],
            [['name' => 'dog', 'friend' => ['name' => 'Alice']], true],
        ];
    }
}

class TestUser extends Model
{
}
