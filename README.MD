# NEO Framework

Welcome, friend! This is my project designed specifically to be placed on a resume as an indicator of my skills. I purposely didn't use any third party libraries to show pure development skill.

I like the Laravel framework, so this project is not particularly different from it. This project can even be called a stripped-down Laravel.

It implements the minimum functionality required to develop a standard web application.

### Set-up
0. `git pull`
1. `make build`
2. `make up`
3. `make shell`
4. `composer install`
5. `composer update`
6. `php neocmd db:migrate`
7. `php neocmd db:seed 20`
8. Enjoy http://localhost:11000/

### Features:
- Controllers
- Query Builder
- Migrations
- Seeders
- Routing
- Blade-like template engine
- Artisan-like console
- CSRF form protector
- A many useful helpers

### NEO CMD
To call console type `php neocmd help` in project root.

<img alt="cmd" src="cmd.png" width="600"/>

**But keep in mind** I did not implement any validation in the console, so you need to be extremely careful about the arguments that you pass to this console.