# Командный процессор

Нам часто требуется написать команду в консоль чтобы исполнить 
код. Например, команда запуска миграций. Для этого и существует 
этот командный процессор.

## Инициализация

В корне Вашего проекта можно создать файл `artisan` с таким 
содержимым. В будущем это позволит выполнять команду 
`php artisan` прямо из консоли со всеми Вашими командами.
Файл `artisan` не должен иметь никакого расширения.

```php 
#!/usr/bin/env php
<?php

# Выполняем код внутри функции exit() для предотвращения
# Исполнения лишнего кода. Это безопасность.
#
exit(
    new Neo\Console\CommandProcessor(

        # Прокидываем контейнер для внедрения зависимостей.
        #
        container: app()->getContainer(),

        # Сюда мы передаем аргументы консоли.
        # Это базовая переменная PHP.
        #
        arguments: $argv,

        # Сюда мы передаем листинг доступных команд.
        #
        commands: [],


    )->run()
);
```

## Пример команды 

```php 
class HelloCommand extends Command
{
    protected string $signature = 'hello {name=мир} {--shout}';
    protected string $description = 'Здоровается';

    public function handle(): int
    {
        $this->info('Привет, ' . $this->argument('name'));

        return self::SUCCESS;
    }
}
```

Теперь нам доступно `php artisan hello World`. В консоль будет 
выведено: `Привет, World`. Флаг тоже доступен, команда будет 
такой: `php artisan hello World --shout`.