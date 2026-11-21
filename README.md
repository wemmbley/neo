<img src="./docs/Images/Neo.png">

# Neo PHP Framework

Ядро приложения для PHP. PHP 8.5+

# Расширения

## 🏗️🧱Foundation
- Автозагрузчик классов и файлов.
- Автоматическая проверка версии PHP и пакетов.
- Конфигурации в массиве и хелпер `config()`/`env()`.
- ServiceProvider, ServiceContainer, Facade.
- Консоль приложения и отдельные команды `php cli`.

## 🩺🩸Diagnostic
- Встроенный тестировщик `php cli test`
- Встроенный дампер `dd()`/`dump()`
- Профилировщик с замерами `profiler()`.

## Консоль
<img src="./docs/Images/Console.png">

## Тестирование
<img src="./docs/Images/Testing.png">

## Профайлер
<img src="./docs/Images/Profiler.png">

Просто пишем `profiler()` в нужном месте. 
Обязательное условие: `APP_PROFILER=true` в `.env`.