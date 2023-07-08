# NEO Framework

Welcome, friend! This is my project designed specifically to be placed on a resume as an indicator of my skills. I purposely didn't use any third party libraries to show pure development skill.

I like the Laravel framework, so this project is not particularly different from it. This project can even be called a stripped-down Laravel.

It implements the minimum functionality required to develop a standard web application.

### Quick set-up (30sec)
0. `git init`
1. `git remote add origin git@github.com:wemmbley/neo3.git`
2. `git pull origin master`
3. `make build`
4. `make up`
5. `make shell`
6. `composer install`
7. `composer update`
8. `php neocmd db:migrate`
9. `php neocmd db:seed 20`
10. Enjoy! ;) http://localhost:11000/

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