<x-mail::message>
# You've Been Enrolled in a Training Course

Hello {{ $recipientName }},

You have been enrolled in the following training course and it is now available for you to start.

<x-mail::panel>
**{{ $courseTitle }}**
@if($description)
{{ $description }}
@endif
</x-mail::panel>

@if($dueDate)
**Completion deadline:** {{ \Carbon\Carbon::parse($dueDate)->format('F j, Y') }}
@endif

<x-mail::button :url="$loginUrl">
Start Learning
</x-mail::button>

Log in to the HR portal to access the course, track your progress, and complete any required assessments.

Thanks,
{{ config('app.name') }}
</x-mail::message>
