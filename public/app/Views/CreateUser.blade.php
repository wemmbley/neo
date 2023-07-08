<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Create user</title>
</head>
<body>
<form action="/createUser" method="post">
    @csrf
    <p>Name</p>
    <input type="text" name="name">
    <p>Email</p>
    <input type="text" name="email">
    <br>
    <input type="submit">
</form>
</body>
</html>