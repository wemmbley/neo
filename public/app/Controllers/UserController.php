<?php

declare(strict_types=1);

namespace App\App\Controllers;

use App\App\Middlewares\UserMiddleware;
use App\App\Services\UserService;
use App\Neo\Database\DB;
use App\Neo\Http\Request;
use App\Neo\Http\Response;
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

        UserService::deleteUser($userId);

        Response::redirect('/users');
    }

    /**
     * POST /updateUser
     *
     * @return void
     */
    public function updateUser(): void
    {
        UserMiddleware::checkCsrf();

        $user = Request::post();

        UserService::updateUser($user);

        Response::redirect('/users');
    }

    /**
     * POST /createUser
     *
     * @return void
     */
    public function createUser(): void
    {
        UserMiddleware::checkCsrf();

        $user = Request::post();

        UserService::createUser($user);

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

        $user = UserService::editUser($userId);

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

        $user = UserService::getUser($userId);

        View::template('UserShow')->withParams([
            'user' => $user
        ])->display();
    }
}