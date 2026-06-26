<?php

namespace App\Http\Controllers;

use App\Mail\SupportReceiptMail;
use App\Mail\SupportResolutionMail;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SupportController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    public function submit(Request $request): JsonResponse
    {
        $request->validate([
            'description' => ['required', 'string', 'max:5000'],
        ]);

        $user     = auth()->user();
        $ticketNo = 'K360-' . strtoupper(Str::random(12));

        $company = $this->settings->module('company');

        $this->postToSlack(
            name:        $user->name,
            email:       $user->email,
            subject:     $ticketNo,
            description: $request->description,
            ticketNo:    $ticketNo,
        );

        Mail::to($user->email)
            ->bcc(config('app.support_mail'))
            ->queue(new SupportReceiptMail(
            name:           $user->name,
            requestSubject: ($company['name'] ?? 'Kazi360'),
            ticketNo:       $ticketNo,
        ));

        return response()->json(['message' => 'Support request submitted successfully.', 'ticket_number' => $ticketNo]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $request->validate([
            'to_email'   => ['required', 'email'],
            'to_name'    => ['nullable', 'string', 'max:255'],
            'subject'    => ['required', 'string', 'max:255'],
            'message'    => ['required', 'string', 'max:10000'],
        ]);

        Mail::to($request->to_email, $request->to_name)->queue(new SupportResolutionMail(
            toName:       $request->to_name ?? $request->to_email,
            emailSubject: $request->subject,
            body:         $request->message,
        ));

        return response()->json(['message' => 'Resolution email sent successfully.']);
    }

    private function postToSlack(string $name, string $email, string $subject, string $description, string $ticketNo): void
    {
        $webhookUrl = config('services.slack.support_webhook');

        if (!$webhookUrl) return;

        $submittedAt = now()->format('D, d M Y \a\t g:i A');

        Http::post($webhookUrl, [
            'text'   => "🎫 [{$ticketNo}] New support request from {$name}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => "🎫 New Support Request · {$ticketNo}"],
                ],
                [
                    'type'   => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Name:*\n{$name}"],
                        ['type' => 'mrkdwn', 'text' => "*Email:*\n<mailto:{$email}|{$email}>"],
                        ['type' => 'mrkdwn', 'text' => "*Subject:*\n{$subject}"],
                        ['type' => 'mrkdwn', 'text' => "*Submitted:*\n{$submittedAt}"],
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Description:*\n" . collect(explode("\n", $description))
                            ->map(fn ($l) => "> {$l}")
                            ->join("\n"),
                    ],
                ],
                ['type' => 'divider'],
                [
                    'type'     => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "Reply to: <mailto:{$email}?subject=Re: [{$ticketNo}] {$subject}|{$email}>",
                        ],
                    ],
                ],
            ],
        ]);
    }
}
