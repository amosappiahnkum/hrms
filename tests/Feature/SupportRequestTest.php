<?php

namespace Tests\Feature;

use App\Mail\SupportReceiptMail;
use App\Mail\SupportRequestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** A support request reaches the support team with the message, ready to reply to; the sender gets a receipt. */
class SupportRequestTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_the_support_team_gets_the_message_and_replies_go_to_the_sender(): void
    {
        $this->setUpAccess([]);
        Mail::fake();
        Http::fake();
        config(['app.support_mail' => 'support@example.com']);
        $user = $this->userWithRole('staff');
        Sanctum::actingAs($user);

        $ticket = $this->postJson('/api/v1/support', ['description' => "I can't open my payslip.\nIt says not found."])->assertOk()->json('ticket_number');

        Mail::assertQueued(SupportRequestMail::class, function (SupportRequestMail $mail) use ($user, $ticket) {
            $html = $mail->render();

            return $mail->hasTo('support@example.com') && $mail->hasReplyTo($user->email)
                && str_contains($mail->envelope()->subject, $ticket)
                && str_contains($html, 'open my payslip.') && str_contains($html, 'It says not found.');
        });
        Mail::assertQueued(SupportReceiptMail::class, fn ($mail) => $mail->hasTo($user->email) && !$mail->hasBcc('support@example.com'));
    }
}
