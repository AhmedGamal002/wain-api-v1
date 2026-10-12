<?php

namespace Tests\Feature\Api;

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * شكل الخطأ في 06 القسم 1: { message, code, errors? } بلغة الطلب.
 */
class ApiErrorFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/v1/_probe')->middleware('api')->group(function () {
            Route::post('validation', function (Request $request) {
                $request->validate(['email' => 'required|email']);
            });
            Route::get('protected', fn () => 'ok')->middleware('auth:sanctum');
            Route::get('forbidden', fn () => throw new AuthorizationException);
            Route::get('domain', fn () => throw new ApiException(401, 'INVALID_CREDENTIALS', 'Wrong email or password'));
            Route::get('conflict', fn () => throw new ApiException(409, 'ALREADY_REVIEWED', '', ['place' => ['x']], ['X-Test' => '1']));
            Route::get('boom', fn () => throw new \RuntimeException('secret internals'));
            Route::get('throttled', fn () => 'ok')->middleware('throttle:1,1');
        });
    }

    public function test_unknown_route_is_not_found_envelope(): void
    {
        $this->getJson('/api/v1/nope', ['Accept-Language' => 'ar'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'مش موجود.', 'code' => 'NOT_FOUND']);
    }

    public function test_validation_error_has_code_and_field_errors(): void
    {
        $response = $this->postJson('/api/v1/_probe/validation', ['email' => 'not-an-email']);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['message', 'code', 'errors' => ['email']]);
        $this->assertIsArray($response->json('errors.email'));
    }

    public function test_unauthenticated_request_gets_401_envelope(): void
    {
        $this->getJson('/api/v1/_probe/protected')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_forbidden_maps_to_403(): void
    {
        $this->getJson('/api/v1/_probe/forbidden')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_domain_exception_keeps_its_code_and_message(): void
    {
        $this->getJson('/api/v1/_probe/domain')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Wrong email or password', 'code' => 'INVALID_CREDENTIALS']);
    }

    public function test_domain_exception_can_carry_errors_and_headers(): void
    {
        $this->getJson('/api/v1/_probe/conflict')
            ->assertStatus(409)
            ->assertHeader('X-Test', '1')
            ->assertJsonPath('code', 'ALREADY_REVIEWED')
            ->assertJsonPath('errors.place.0', 'x');
    }

    public function test_rate_limit_returns_429_with_retry_after(): void
    {
        $this->getJson('/api/v1/_probe/throttled')->assertOk();

        $this->getJson('/api/v1/_probe/throttled', ['Accept-Language' => 'en'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
            ->assertJsonPath('message', 'Too many requests. Please try again shortly.');
    }

    public function test_message_follows_request_language(): void
    {
        $this->getJson('/api/v1/nope', ['Accept-Language' => 'en'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');
    }

    public function test_unexpected_error_does_not_leak_details_outside_debug(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_probe/boom', ['Accept-Language' => 'ar']);

        $response->assertStatus(500)
            ->assertExactJson(['message' => 'حصل خطأ، حاول تاني.', 'code' => 'SERVER_ERROR']);
        $this->assertStringNotContainsString('secret internals', $response->getContent());
    }
}
