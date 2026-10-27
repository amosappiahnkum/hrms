<x-mail::message>
# New support request · {{ $ticketNo }}

<x-mail::panel>
**From:** {{ $name }} &lt;{{ $email }}&gt;<br>
**Organization:** {{ $organization }}<br>
**Submitted:** {{ $submittedAt }}
</x-mail::panel>

**Message**

{!! nl2br(e($description)) !!}

---

Reply to this email to answer {{ $name }} directly.
</x-mail::message>
