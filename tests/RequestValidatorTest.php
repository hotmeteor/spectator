<?php

namespace Spectator\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\SubstituteBindings;
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

        Spectator::using('Global.v1.yml');

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

        Spectator::using('Global.v1.yml');

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

        Route::get('/users/{user}', function (TestUser $user) {
            return [
                'id' => 1,
                'name' => 'Jim',
                'email' => 'test@test.test',
            ];
        })->middleware([SubstituteBindings::class, Middleware::class]);

        $this->getJson('/users/1')
            ->assertValidRequest();
    }

    public function test_resolve_route_model_explicit_binding(): void
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', fn () => new TestUser);

        Route::get('/posts/{postUuid}', function () {
            return [
                'id' => 1,
                'title' => 'My Post',
            ];
        })->middleware([SubstituteBindings::class, Middleware::class]);

        $this->getJson('/posts/'.Str::uuid()->toString())
            ->assertValidRequest();
    }

    public function test_cannot_resolve_route_model_explicit_binding_with_invalid_format(): void
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', fn () => new TestUser);

        Route::get('/posts/{postUuid}', function () {
            return [
                'id' => 1,
                'title' => 'My Post',
            ];
        })->middleware([SubstituteBindings::class, Middleware::class]);

        $this->getJson('/posts/invalid')
            ->assertInvalidRequest();
    }

    public function test_resolve_route_model_binding_with_multiple_parameters(): void
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', fn () => new TestUser);

        Route::get('/posts/{postUuid}/comments/{comment}', function () {
            return [
                'id' => 1,
                'message' => 'My Comment',
            ];
        })->middleware([SubstituteBindings::class, Middleware::class]);

        $this->getJson('/posts/'.Str::uuid()->toString().'/comments/1')
            ->assertValidRequest();
    }

    /**
     * @dataProvider nullableProvider
     */
    public function test_handle_nullables($version, $state, $isValid): void
    {
        Spectator::using("Nullable.$version.json");

        Route::post('/users', fn () => 'ok')->middleware(Middleware::class);

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

        if ($isValid) {
            $this->postJson('/users', $payload)
                ->assertValidRequest();
        } else {
            $this->postJson('/users', $payload)
                ->assertInvalidRequest();
        }
    }

    public static function nullableProvider(): array
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

    public static function oneOfSchemaProvider(): array
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

    public static function anyOfSchemaProvider(): array
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

    public static function allOfSchemaProvider(): array
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

        $this->get('/users?order=invalid')
            ->assertValidationMessage('The data should match one item from enum')
            ->assertInvalidRequest();

        $this->get('/users?order=')
            ->assertValidRequest();

        $this->get('/users?order=name')
            ->assertValidRequest();

        $this->get('/users?order=email')
            ->assertValidRequest();

        $this->get('/users?order=email,name')
            ->assertValidationMessage('The data should match one item from enum')
            ->assertInvalidRequest();

        // Test it handles nested query parameters
        Route::get('/orders', function () {
            return [];
        })->middleware(Middleware::class);

        $this->get('/orders')
            ->assertValidationMessage('Missing required query parameter [?filter[groupId]=].')
            ->assertInvalidRequest();

        $this->get('/orders?filter[groupId]=1')
            ->assertValidationMessage('The data must match the \'uuid\' format')
            ->assertInvalidRequest();

        $this->get('/orders?filter[groupId]=cc8936c7-d681-4c42-9410-c50488f43736')
            ->assertValid();
    }

    public function test_handles_array_query_parameters(): void
    {
        Spectator::using('Arrays.v1.yml');

        // When testing query parameters, they are not found nor checked by RequestValidator->validateParameters().
        Route::get('/parameter-as-array', function () {
            return response()->noContent();
        })->middleware(Middleware::class);

        $this->get('/parameter-as-array?arrayParam=foo')
            ->assertValidationMessage('The data (string) must match the type: array')
            ->assertInvalidRequest();

        $this->get('/parameter-as-array?arrayParam[]=foo&arrayParam[]=bar')
            ->assertValidRequest();
    }

    public function test_handles_array_of_object_query_parameters(): void
    {
        Spectator::using('Arrays.v1.yml');

        Route::get('/parameter-as-array-of-objects', function () {
            return response()->noContent();
        })->middleware(Middleware::class);

        $this->get('/parameter-as-array-of-objects?arrayParam[0][id]=1&arrayParam[0][name]=foo&arrayParam[1][id]=2')
            ->assertValidationMessage('The required properties (name) are missing')
            ->assertInvalidRequest();

        $this->get('/parameter-as-array-of-objects?arrayParam[0][id]=1&arrayParam[0][name]=foo&arrayParam[1][id]=2&arrayParam[1][name]=bar')
            ->assertValidRequest();
    }

    public function test_handles_query_parameters_int(): void
    {
        Spectator::using('Test.v1.json');

        // When testing query parameters, they are not found nor checked by RequestValidator->validateParameters().
        Route::get('/users-by-id/{user}', function (string $user) {
            return [];
        })->middleware(Middleware::class);

        $this->getJson('/users-by-id/foo')
            ->assertInvalidRequest();

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
            [
                'name' => 'Adam Campbell',
                'email' => 'test@test.com',
                'picture' => UploadedFile::fake()->image('test.jpg'),
            ],
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

    public function test_numeric_values()
    {
        Spectator::using('Numbers.v1.json');

        Route::get('/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })
            ->middleware(Middleware::class);

        $this->getJson('/users?page=1&per_page=10&float_param=3.14')
            ->assertStatus(200)
            ->assertValidRequest()
            ->assertValidResponse();
    }

    public function test_comma_separated_values()
    {
        Spectator::using('CommaSeparatedString.v1.json');

        Route::get('/users', function () {
            return [
                [
                    'id' => 1,
                    'name' => 'Jim',
                    'email' => 'test@test.test',
                ],
            ];
        })
            ->middleware(Middleware::class);

        $this->getJson('/users?include=foo,bar')
            ->assertStatus(200)
            ->assertValidRequest()
            ->assertValidResponse();
    }

    public static function nullableObjectProvider(): array
    {
        return [
            [['name' => 'dog', 'friend' => null], true],
            [['name' => 'dog', 'friend' => ['name' => 'Alice']], true],
            [['name' => 'dog', 'friend' => ['name' => 'Alice', 'age' => null]], true],
        ];
    }

    public function test_ignores_request_validation_if_not_asserted(): void
    {
        Spectator::using('Test.v1.json');

        Route::bind('postUuid', TestUser::class);

        $uuid = Str::uuid();

        Route::get('/posts/{postUuid}', function () {
            return [
                'id' => 1,
                'title' => 'My Post',
            ];
        })->middleware(Middleware::class);

        $this->getJson("/posts/{$uuid}")
            ->assertValidRequest()
            ->assertValidResponse();

        $this->getJson('/posts/invalid')
            ->assertInvalidRequest()
            ->assertValidResponse();

        $this->getJson("/posts/{$uuid}")
            ->assertValidResponse();

        $this->getJson('/posts/invalid')
            ->assertValidResponse();
    }

    /**
     * @dataProvider requiredReadOnlySchemaProvider
     */
    public function test_required_readonly(
        $payload,
        $is_valid
    ): void {
        Spectator::using('RequiredReadOnly.v1.yml');

        Route::post('/users', fn () => 'ok')->middleware(Middleware::class);

        if ($is_valid) {
            $this->postJson('/users', $payload)
                ->assertValidRequest();
        } else {
            $this->postJson('/users', $payload)
                ->assertInvalidRequest();
        }
    }

    public static function requiredReadOnlySchemaProvider(): array
    {
        $valid = true;
        $invalid = false;

        return [
            'valid, Readonly not passed' => [
                [
                    'name' => 'Adam Campbell',
                    'email' => 'adam@hotmeteor.com',
                    'arrayProperty' => [
                        [
                            'name' => 'The Hobbit',
                        ],
                    ],
                    'anyOfProperty' => [
                        'name' => 'The Hobbit',
                    ],
                    'allOfProperty' => [
                        'name' => 'The Hobbit',
                    ],
                    'oneOfProperty' => [
                        'name' => 'The Hobbit',
                    ],
                ],
                $valid,
            ],
            'Invalid, Books not passed' => [
                [
                    'name' => 'Adam Campbell',
                    'email' => 'adam@hotmeteor.com',
                ],
                $invalid,
            ],
            'invalid, Readonly passed' => [
                [
                    'id' => 1,
                    'name' => 'Adam Campbell',
                    'email' => 'adam@hotmeteor.com',
                ],
                $invalid,
            ],
            'invalid, Required not passed' => [
                [
                    'email' => 'adam@hotmeteor.com',
                ],
                $invalid,
            ],
        ];
    }

    /**
     * @dataProvider enumProvider
     */
    public function test_enum_in_path(string $type, bool $isValid): void
    {
        Spectator::using('Enum.yml');

        Route::get('/enum-in-path/{type}', function (TestEnum $type) {
            return response()->noContent();
        })->middleware([SubstituteBindings::class, Middleware::class]);

        if ($isValid) {
            $this->getJson("/enum-in-path/{$type}")
                ->assertValidRequest();
        } else {
            $this->getJson("/enum-in-path/{$type}")
                ->assertInvalidRequest();
        }
    }

    /**
     * @dataProvider enumProvider
     */
    public function test_enum_in_path_via_reference(string $type, bool $isValid): void
    {
        Spectator::using('Enum.yml');

        Route::get('/enum-in-path-via-reference/{type}', function (TestEnum $type) {
            return response()->noContent();
        })->middleware([SubstituteBindings::class, Middleware::class]);

        if ($isValid) {
            $this->getJson("/enum-in-path-via-reference/{$type}")
                ->assertValidRequest();
        } else {
            $this->getJson("/enum-in-path-via-reference/{$type}")
                ->assertInvalidRequest();
        }
    }

    public static function enumProvider(): array
    {
        return [
            'valid enum' => [
                'name',
                true,
            ],
            'invalid enum' => [
                'invalid',
                false,
            ],
        ];
    }

    public function test_validates_upload_file(): void
    {
        Spectator::using('Upload.yml');

        Route::post('/upload', function () {
            return response()->noContent();
        })->middleware(Middleware::class);

        $this->post('/upload', ['file' => UploadedFile::fake()->createWithContent('test.xlsx', 'Content')], ['Content-Type' => 'multipart/form-data'])
            ->assertValidRequest();
    }

    public function test_parameter_decoupling(): void
    {
        Spectator::using('Test.v1.json');

        Route::get('/users/{id}', function (TestUser $id) {
            return [
                'id' => 1,
                'name' => 'Jim',
                'email' => 'test@test.test',
            ];
        })->middleware([SubstituteBindings::class, Middleware::class]);

        $this->getJson('/users/1')
            ->assertValidRequest();
    }
}

class TestUser extends Model
{
    public function resolveRouteBinding($value, $field = null)
    {
        return new TestUser();
    }
}

enum TestEnum: string
{
    case name = 'name';
    case email = 'email';
    case invalid = 'invalid';
}
