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
        $userId = Request::input('id');

        DB::table('users')
            ->where('id', '=', $userId)
            ->delete();

        Response::redirect('/users');
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

        $user = Request::post();

        DB::table('users')
            ->where('id', '=', $user->id)
            ->update([
                'name' => $user->name,
                'email' => $user->email
            ]);

        Response::redirect('/users');
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

        $user = Request::post();

        DB::table('users')->insert([
            'name' => $user->name,
            'email' => $user->email
        ]);

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
        $userId = Request::input('id');

        $user = DB::table('users')
            ->where('id', '=', $userId)
            ->first();

        View::template('EditUser')->withParams([
            'user' => $user
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
        $userId = Request::input('id');

        $user = DB::table('users')
            ->where('id', '=', $userId)
            ->first();

        View::template('UserShow')->withParams([
            'user' => $user
        ])->display();
    }
}