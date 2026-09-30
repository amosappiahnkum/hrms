<?php

namespace Tests\Unit;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_unexpected_exceptions_are_logged_and_replaced_with_a_generic_message(): void
    {
        Log::spy();

        $response = ApiResponse::fromException(
            new RuntimeException("SQLSTATE[42S22]: Unknown column 'secret_col' in 'employees'")
        );

        $this->assertSame(500, $response->status());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('secret_col', $response->getContent());
        $this->assertSame('Something went wrong. Please try again.', $response->getData(true)['message']);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_the_fallback_message_can_be_customised(): void
    {
        $response = ApiResponse::fromException(new RuntimeException('internal'), 'Unable to approve request');

        $this->assertSame('Unable to approve request', $response->getData(true)['message']);
    }

    public function test_aborts_keep_their_message_and_status(): void
    {
        $response = ApiResponse::fromException(new HttpException(403, 'You do not have access to this document.'));

        $this->assertSame(403, $response->status());
        $this->assertSame('You do not have access to this document.', $response->getData(true)['message']);
    }

    public function test_user_facing_exceptions_are_shown(): void
    {
        $response = ApiResponse::fromException(new UserFacingException('This request has already been processed.', 409));

        $this->assertSame(409, $response->status());
        $this->assertSame('This request has already been processed.', $response->getData(true)['message']);
    }

    public function test_missing_records_return_404_without_model_details(): void
    {
        $response = ApiResponse::fromException((new ModelNotFoundException())->setModel('App\\Models\\Secret', [7]));

        $this->assertSame(404, $response->status());
        $this->assertStringNotContainsString('App\\\\Models', $response->getContent());
    }

    public function test_validation_errors_are_returned(): void
    {
        $response = ApiResponse::fromException(ValidationException::withMessages(['staff_id' => 'Taken.']));

        $this->assertSame(422, $response->status());
        $this->assertSame(['staff_id' => ['Taken.']], $response->getData(true)['errors']);
    }
}
