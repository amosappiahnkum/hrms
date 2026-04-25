<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: sans-serif; line-height: 1.6; }
        .button {
            background-color: #3490dc;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
        }
    </style>
</head>
<body>
<h1>Email Notification</h1>

<p>{{ $body }}</p>

<a href="{{ config('app.url') }}" class="button">View Details</a>

<p>Thanks,<br>{{ config('app.name') }}</p>
</body>
</html>
