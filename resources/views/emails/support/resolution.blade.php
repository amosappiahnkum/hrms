<x-mail::message>
# Support Request Update

Hello **{{ $toName }}**,

{!! nl2br(e($body)) !!}

---

If you have further questions, feel free to submit a new support request through the HRMS portal.

Thanks,
{{ config('app.name') }} Support Team
</x-mail::message>
