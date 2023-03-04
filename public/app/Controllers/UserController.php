<?php

declare(strict_types=1);

namespace App\App\Controllers;

use App\Neo\Database\DB;
use App\Neo\Http\Request;
use App\Neo\Http\Response;
use App\Neo\Protector;
use App\Neo\View;

class UserController
{
    /**
     * GET /deleteUser/{id}
     *
     * @return void
     */
    public function deleteUser(): void
    {
        $userId = Request::param('id');

        DB::table('users')
            ->where('id', '=', $userId)
            ->delete();

        Response::send();
    }

    /**
     * POST /updateUser
     *
     * @return void
     */
    public function updateUser(): void
    {
        if ( ! Protector::isCsrf()) {
            Response::status(403)
                ->send();
        }

        $userInfo = Request::post();

        DB::table('users')
            ->where('id', '=', $userInfo['id'])
            ->update([
                'name' => $userInfo['name'],
                'email' => $userInfo['email']
            ]);

        Response::send();
    }

    /**
     * POST /createUser
     *
     * @return void
     */
    public function createUser(): void
    {
        if ( ! Protector::isCsrf()) {
            Response::status(403)
                ->send();
        }

        $userInfo = Request::post();

        DB::table('users')->insert([
            'name' => $userInfo['name'],
            'email' => $userInfo['email']
        ]);

        Response::send();

        // @todo redirect not working
        Response::redirect('/users');
    }

    /**
     * GET /editUser/{id}
     *
     * @return void
     * @throws \Exception
     */
    public function editUser(): void
    {
        $userId = Request::param('id');

        $user = DB::table('users')
            ->where('id', '=', $userId)
            ->first();

        View::template('EditUser')->withParams([
            'userId' => $user[0]['id'],
            'userName' => $user[0]['name'],
            'userEmail' => $user[0]['email']
        ])->display();
    }

    /**
     * GET /createUser
     *
     * @return void
     * @throws \Exception
     */
    public function showCreateUser(): void
    {
        View::template('CreateUser')->display();
    }

    /**
     * GET /users/
     *
     * @return void
     * @throws \Exception
     */
    public function showAllUsers(): void
    {
        $users = DB::table('users')
            ->get();

        View::template('AllUsers')->withParams([
            'users' => $users
        ])->display();
    }

    /**
     * GET /user/{id}
     *
     * @return void
     * @throws \Exception
     */
    public function showUser(): void
    {
        $userId = Request::param('id');

        $user = DB::table('users')
            ->where('id', '=', $userId)
            ->first();

        View::template('UserShow')->withParams([
            'user' => $user
        ])->display();
    }
}