<x-mail::message>
# New Approval Request
Hello,
**{{ $approval->requestedBy?->employee?->name }}** has submitted a request to:
<x-mail::panel>
{{ strtoupper($approval->type) }} — {{ class_basename($approval->information_type) }}
</x-mail::panel>
The request is currently awaiting review and approval.



**Submitted on:**
{{ $approval->created_at->format('F j, Y \a\t g:i A') }}
<x-mail::button :url="config('app.frontend_url')">
Review Request
</x-mail::button>
Please log into the HR system to review the request details.


Thanks,
<br>
{{ config('app.name') }}
</x-mail::message>
