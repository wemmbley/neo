# Authorization: Roles, Permissions, Guard, Policy, Gate

Авторизация в духе Laravel + spatie/laravel-permission. Работает поверх минимальной
аутентификации (`Auth`, охранники), которая лежит в `neo/Security/Authentication`.

## Подключение

1. `AuthServiceProvider` уже в `app/Config/App.php` (после ORM), хелперы в `app/Config/Bootstrap.php`.
2. Миграция: `php cli migrate` (файл `app/Database/Migrations/UsersAuthorization.php`, идёт после `Users.php`).
3. Сидер ролей: `php cli db:seed`. Первый админ: `php cli role:assign you@mail.com admin`.
4. Настройки: `app/Config/Auth.php`.

## Роли и права (модель User уже подключена)

```php
$user->assignRole('admin');                 // роль должна существовать, иначе RoleDoesNotExist
$user->givePermissionTo('post.update');     // право напрямую
$user->syncRoles('editor');
$user->hasRole('admin|editor');             // любая из
$user->hasAllRoles('a', 'b');
$user->hasPermissionTo('post.read');        // прямое + через роли; `post.*` покрывает `post.read`
Role::findOrCreate('editor')->givePermissionTo('post.read', 'post.update');
```

Роли и права кешируются в экземпляре модели; правки через модели кеш сбрасывают.

## Gate и политики

```php
Gate::define('ban-user', fn (User $u) => $u->hasRole('moderator'));
Gate::allows('ban-user');                   // текущий пользователь
Gate::allows('update', $post);              // App\Policies\PostPolicy::update($user, $post)
Gate::allows('create', Post::class);        // PostPolicy::create($user)
Gate::authorize('update', $post);           // иначе AuthorizationException (403)
$user->can('update', $post);
```

Политика ищется: `Gate::policy()` → `#[UsePolicy]` на модели → `App\Policies\{Модель}Policy`.
Нет политики и способности: запрет. Гость допускается, только если тип первого аргумента nullable.
Права тоже проверяются через Gate: `can('post.update')` (отключается `gate_checks_permissions`).
Суперадмин: `super_admin_role` в конфиге.

## Middleware

| Псевдоним | Пример |
|---|---|
| `auth` | `->middleware('auth')`, `auth:api` |
| `can` | `can:update,post`, `can:create,App\Database\Models\Post` |
| `role` | `role:admin\|moderator` |
| `permission` | `permission:post.delete` |
| `role_or_permission` | `role_or_permission:admin\|post.update` |
| `admin` | роль из `admin_role` |

Гость получает 401 (браузер: редирект на `auth.redirect_to`), без прав: 403.

## Охранники

`web` держит пользователя в сессии (`Auth::attempt()`, `Auth::login()`, `Auth::logout()`).
`api` без сессии: логика в `Auth::viaRequest('api', fn (Request $r) => ...)`, например в `AppServiceProvider::boot()`.
`$request->user()`, `auth()->user()`, `user()` дают текущего пользователя.

## Не реализовано

Таблицы переводов ролей и прав из спецификации, сброс пароля, remember-token, scopes `User::role()`.
