<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Edit user</title>
</head>
<body>
<form action="/updateUser" method="post">
    @csrf
    <input type="hidden" name="id" value="{{ $user->id }}">
    <p>Name</p>
    <input type="text" name="name" value="{{ $user->name }}">
    <p>Email</p>
    <input type="text" name="email" value="{{ $user->email }}">
    <br>
    <input type="submit">

    <br>
    <a href="/users"><–– Back to the table</a>
</form>
</body>
</html>