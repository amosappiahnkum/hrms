<x-mail::message>
# Email Notification

{{ $body }}

<x-mail::button :url="config('app.url')">
View Details
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
