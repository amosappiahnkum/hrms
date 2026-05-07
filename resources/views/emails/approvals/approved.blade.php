<x-mail::message>
# Request Approved

Hello {{ $approval->requestedBy?->employee?->name }},

Your request to **{{ strtolower($approval->type) }}** your **{{ class_basename($approval->information_type) }}** information has been **approved successfully**.

The request was reviewed and approved by **{{ $approval->reviewedBy?->employee?->name }}**.

**Approved on:**
{{ \Carbon\Carbon::parse($approval->reviewed_at)->format('F j, Y \a\t g:i A') }}

Your records have now been updated in the system. No further action is required.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
