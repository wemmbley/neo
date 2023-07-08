<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link rel="stylesheet" href="{{ asset('css/main.css') }}">
    <title>Show user</title>
</head>
<body>
    <div class="user-page">
        @if(empty($user))
            User not found.
        @else
            <div class="hello">
                <img src="{{ asset('img/icons/hi-icon.svg') }}" alt="hi icon" width="48px">
                <p>Hello, {{ $user->name }}.</p>
            </div>
        @endif
        <a href="/users">Back to users</a>
    </div>
</body>
</html>