<x-mail::message>
# Support Request Received

Hello {{ $name }},

Thank you for reaching out. We have received your support request and our team is looking into it.

<x-mail::panel>
**Ticket Number:** {{ $ticketNo }}


**Subject:** {{ $requestSubject }}
</x-mail::panel>

Please keep your ticket number for reference. We will get back to you as soon as possible. If your request is urgent, please contact your HR department directly.

Thanks,
{{ config('app.name') }} Support Team
</x-mail::message>
