<?php

namespace App\Http\Controllers;

use App\Mail\QuickMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class QuickEmailController extends Controller
{
    public function send(Request $request)
    {
        $validated = $request->validate([
            'to' => 'required|email',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'cc' => 'nullable|array',
            'cc.*' => 'email',
            'bcc' => 'nullable|array',
            'bcc.*' => 'email',
            'attachments' => 'nullable|array', // Assuming array of file paths
            'attachments.*' => 'file|mimes:pdf,jpg,png|max:5120', // 5MB limit
        ]);

        $mailable = new QuickMail($validated['subject'], $validated['body'], $validated['attachments'] ?? []);

        // Handle CC and BCC
        if (!empty($validated['cc'])) $mailable->cc($validated['cc']);
        if (!empty($validated['bcc'])) $mailable->bcc($validated['bcc']);

        // Queue the mail
        Mail::to($validated['to'])->queue($mailable);

        return response()->json(['message' => 'Email queued successfully!']);
    }
}
