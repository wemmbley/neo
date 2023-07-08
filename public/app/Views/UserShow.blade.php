<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Show user</title>
</head>
<body>
    @if(empty($user))
        User not found.
    @else
        Hello, {{ $user->name }}.
    @endif

    <br>
    <a href="/users">Back to the users table</a>
</body>
</html>