<?php

namespace App\Http\Controllers;

use App\Mail\QuickMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,jpg,png|max:5120',
        ]);

        // 1. Process attachments: store them and keep the paths
        $attachmentPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                // Store in storage/app/mail-attachments
                if (!Storage::disk('local')->exists('mail-attachments')) {
                    Storage::disk('local')->makeDirectory('mail-attachments');
                }

                // Store the file and get the path
                $path = $file->store('mail-attachments', 'local');

                // Use Storage::path() to get the absolute system path
                $attachmentPaths[] = Storage::disk('local')->path($path);
            }
        }

        // 2. Pass the array of STRING paths, not the file objects
        $mailable = new QuickMail($validated['subject'], $validated['body'], $attachmentPaths);

        if (!empty($validated['cc'])) $mailable->cc($validated['cc']);
        if (!empty($validated['bcc'])) $mailable->bcc($validated['bcc']);

        Mail::to($validated['to'])->queue($mailable);

        return response()->json(['message' => 'Email queued successfully!']);
    }
}
