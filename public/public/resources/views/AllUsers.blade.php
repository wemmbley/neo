<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link rel="stylesheet" href="{{ asset('css/main.css') }}">
    <title>All users</title>
</head>
<body>
    <div class="container">
        <table>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Actions</th>
            </tr>
            @foreach($users as $user)
                <tr>
                    <td>{{ $user->id }}</td>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>
                        <a href="/user/{{ $user->id }}">Show</a>
                        <a href="/editUser/{{ $user->id }}">Edit</a>
                        <a href="/deleteUser/{{ $user->id }}">Delete</a>
                    </td>
                </tr>
            @endforeach
        </table>

        <div class="links">
            <div class="image-link">
                <img src="{{ asset('img/icons/circle-plus.svg') }}" alt="plus icon" width="24px">
                <a href="/createUser">Add new user</a>
            </div>

            <div class="image-link">
                <img src="{{ asset('img/icons/home.svg') }}" alt="home icon" width="24px">
                <a href="/">Back home</a>
            </div>
        </div>
    </div>
</body>
</html>