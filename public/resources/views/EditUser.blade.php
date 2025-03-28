<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link rel="stylesheet" href="{{ asset('css/main.css') }}">
    <title>Edit user</title>
</head>
<body>
<form action="/updateUser" method="post">
    @csrf
    <div class="create-user">
        <h2>EDIT USER {{ Str::upper($user->name) }}</h2>
        <input type="hidden" name="id" value="{{ $user->id }}">
        <p>Name</p>
        <input type="text" name="name" value="{{ $user->name }}">
        <p>Email</p>
        <input type="text" name="email" value="{{ $user->email }}">
        <br>
        <input type="submit">

        <div class="image-link">
            <img src="{{ asset('img/icons/back-icon.svg') }}" alt="back" width="24px">
            <a href="/users">Back to users</a>
        </div>
    </div>
</form>
</body>
</html>