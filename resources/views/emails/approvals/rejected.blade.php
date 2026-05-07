<x-mail::message>
# Request Rejected

Hello {{ $approval->requestedBy?->employee?->name }},

Your request to **{{ strtolower($approval->type) }}** your **{{ class_basename($approval->information_type) }}** information has been **rejected**.

<x-mail::panel>
### Reason for Rejection
{{ $approval->rejection_reason }}
</x-mail::panel>

**Reviewed by:**
{{ $approval->reviewedBy?->employee?->name }}

If necessary, you may review the feedback, make the required corrections, and submit a new request.


Thanks,
<br>
{{ config('app.name') }}
</x-mail::message>
