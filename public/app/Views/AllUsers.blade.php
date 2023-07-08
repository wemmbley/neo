<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>All users</title>
</head>
<body>
    <table>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
        </tr>
        @foreach($users as $user)
            <tr>
                <td>{{ $user->id }}</td>
                <td>{{ $user->name }}</td>
                <td>{{ $user->email }}</td>
                <td><a href="/user/{{ $user->id }}">Show</a></td>
                <td><a href="/editUser/{{ $user->id }}">Edit</a></td>
                <td><a href="/deleteUser/{{ $user->id }}">Delete</a></td>
            </tr>
        @endforeach
    </table>

    <br>
    <a href="/createUser">(+) Add new user</a>
    <br><br>
    <a href="/"><–– Back to the main page</a>
</body>
</html>