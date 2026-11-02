<?php

namespace Tests\Feature;

use App\Exceptions\UserFacingException;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class ErrorResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Worst case: debug left on in production.
        config(['app.debug' => true]);

        // Only these routes, so the SPA catch-all in web.php doesn't swallow them.
        Route::setRoutes(new RouteCollection());

        Route::middleware('api')->prefix('api/__errors')->group(function () {
            Route::get('query', fn () => throw new QueryException(
                'mysql',
                'select * from `employees` where `secret_col` = ?',
                ['s3cr3t'],
                new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'secret_col'")
            ));
            Route::get('runtime', fn () => throw new \RuntimeException('Connection refused to redis:6379'));
            Route::get('missing', fn () => User::query()->where('id', -1)->firstOrFail());
            Route::get('bound/{user}', fn (User $user) => $user);
            Route::get('user-facing', fn () => throw new UserFacingException('Already processed.', 409));
            Route::get('aborted', fn () => abort(403, 'You cannot assess this employee.'));
            Route::post('post-only', fn () => 'ok');
            Route::get('private', fn () => 'ok')->middleware('auth:sanctum');
        });
    }

    public function test_database_errors_do_not_leak_sql_or_schema(): void
    {
        $response = $this->getJson('/api/__errors/query');

        $response->assertStatus(500)->assertJson(['message' => 'Something went wrong. Please try again.']);
        foreach (['SQLSTATE', 'secret_col', 's3cr3t', 'employees', 'trace', 'file', 'line', 'exception'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_unexpected_errors_are_generic(): void
    {
        $response = $this->getJson('/api/__errors/runtime');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('redis', $response->getContent());
    }

    public function test_missing_records_do_not_name_the_model(): void
    {
        foreach (['/api/__errors/missing', '/api/__errors/bound/999999'] as $uri) {
            $response = $this->getJson($uri);

            $response->assertStatus(404)->assertJson(['message' => 'Record not found.']);
            $this->assertStringNotContainsString('App\\\\Models', $response->getContent());
        }
    }

    public function test_wrong_methods_do_not_echo_paths(): void
    {
        $response = $this->getJson('/api/__errors/post-only');
        $response->assertStatus(405)->assertJson(['message' => 'This action is not allowed.']);
        $this->assertStringNotContainsString('post-only', $response->getContent());
    }

    public function test_user_facing_messages_are_kept(): void
    {
        $this->getJson('/api/__errors/user-facing')->assertStatus(409)->assertJson(['message' => 'Already processed.']);
        $this->getJson('/api/__errors/aborted')->assertStatus(403)->assertJson(['message' => 'You cannot assess this employee.']);
    }

    public function test_auth_responses_are_unchanged(): void
    {
        $this->getJson('/api/__errors/private')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    }
}
