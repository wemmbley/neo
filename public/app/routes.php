<?php

use App\App\Controllers\UserController;
use App\Neo\Router\Route;
use App\Neo\View;

/**
 * User API
 */
Route::post('/createUser', [UserController::class, 'createUser']);
Route::post('/updateUser', [UserController::class, 'updateUser']);
Route::get('/deleteUser/{id}', [UserController::class, 'deleteUser'])
    ->where(['id' => '[0-9]+']);

/**
 * WEB pages
 */
Route::get('/users', [UserController::class, 'showAllUsers']);
Route::get('/createUser', [UserController::class, 'showCreateUser']);
Route::get('/editUser/{id}', [UserController::class, 'editUser'])
    ->where(['id' => '[0-9]+']);
Route::get('/user/{id}', [UserController::class, 'showUser'])
    ->where(['id' => '[0-9]+']);

Route::get('/', function() {
    View::template('Home');
    View::display();
});