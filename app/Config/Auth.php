<?php

declare(strict_types=1);

return [
    #
    # Охранник по умолчанию: тот, чьего пользователя возвращает Auth::user() и проверяет Gate.
    #
    'defaults' => ['guard' => 'web'],

    #
    # Охранники. driver: session (вход через сессию, нужна группа web)
    #                   request (по запросу: токен, API-ключ; логика в Auth::viaRequest('api', fn ...)).
    #
    'guards' => [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'api' => ['driver' => 'request', 'provider' => 'users'],
    ],

    #
    # Идентификация: где искать пользователей.
    #
    'providers' => [
        'users' => ['driver' => 'model', 'model' => \App\Database\Models\User::class],
    ],

    #
    # Куда отправлять гостя, не прошедшего `auth` (только браузер; JSON-клиент получает 401). null: просто 401.
    #
    'redirect_to' => null,

    'authorization' => [
        # Роль, которой разрешено ВСЁ (Gate::before). null: выключено.
        'super_admin_role' => null,

        # Роль для middleware `admin`.
        'admin_role' => 'admin',

        # `post.*` выдаёт все права на post.*, `*` выдаёт вообще все.
        'wildcards' => true,

        # `$user->can('post.update')` и `can:post.update` смотрят и в права пользователя.
        'gate_checks_permissions' => true,

        # Где искать политики по соглашению: Модель Post => App\Policies\PostPolicy.
        'policies_namespace' => 'App\\Policies\\',

        # Колонка пивот-таблиц, указывающая на пользователя.
        'user_key' => 'user_id',

        'models' => [
            'role' => \Neo\Security\Authorization\Role\Role::class,
            'permission' => \Neo\Security\Authorization\Permission\Permission::class,
        ],

        'tables' => [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'role_permissions' => 'role_permissions',
            'user_roles' => 'user_roles',
            'user_permissions' => 'user_permissions',
        ],
    ],
];
