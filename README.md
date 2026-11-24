<img src="./docs/Images/Neo.png">

# Neo PHP Framework

Ядро синхронного приложения для PHP с возмоможностью асинхронности. 
Использует PHP 8.5+. 

# Расширения

## ⚙️⛓️‍Runtime
- Базовый рантайм синхронного PHP точка старта.
- Swoole Runtime: 'php cli serve --swoole'.
- Возможность делать другие async рантаймы. 
- Coroutines, Background, Streaming, Threads.
- State Reset, Context, Async IO, DB Pool.

## 🏗️🧱Foundation
- Автозагрузчик классов и файлов.
- Автоматическая проверка версии PHP и пакетов.
- Конфигурации в массиве и хелпер `config()`/`env()`.
- ServiceProvider, ServiceContainer, Facade.
- Observer, EventListener, EventDispatcher.

## 🩺🩸Diagnostic
- Встроенный тестировщик `php cli test`.
- Встроенный дампер `dd()`/`dump()`.
- Профилировщик с замерами `profiler()`.
- Логирование данных фасадом `Log::`
- Репортинг логов в разные каналы.

## 💾📂Information
- Файловое хранилище через фасад `Storage::`.
- Реляционное хранилище, миграции, фабрики, сидеры.
- DTO, DataMapper, Validator в одном лице: `Transfer`.
- Универсальное сессионное хранилище: `Session::`
- Лёгкий доступ к браузерным кукам: `Cookie::`
- Специальный фасад для даты и времени `Chronos::`.
- Обёртка над массивами фасадом `Collection::`

## 🗺️🌐Network
- Маршрутизатор HTTP-запросов `Route::`
- Middleware, Request, Response, Controller.
- HTTP-клиент для запросов на сторонние сервисы.
- UDP-клиент: Datagram Request-Response-Header.
- UDP-сервер: можно делать STUN/TURN и другое.
- Автоматическая генерация API: `ResourceAPI`.
- Автоматическая API-документация: `/docs/api`.
- Сортировка, фильтрация, пагинация `ResourceAPI`.

## 🔒🔑Security
- Идентификация, аутентификация, авторизация.
- Roles, Permissions, Guard, Policy, Gate.
- CSRF-защита пользовательских форм.
- XSS-защита, фасад `Crypt::`, CORS.
- Валидация данных через фасад `Validator::`.

## 🌆🌇Presentation
- Консоль приложения и отдельные команды `php cli`.
- Универсальный шаблонизатор HTML `View::`.
- Файловые языки через функцию `__()`.
- Генерация писем и шаблонов фасадом `Mail::`

## 🛡️🛠️Administration
- Отдельная роль для администраторов.
- Dashboard, Widget, DataTable.
- Create, Read, Update, Delete, List.

# Готовые системы

## 📢📝CMS
- Extends Administration functionality.
- Pages, Taxonomies, Tags, Categories.
- Menu, Comments, Posts, Templates.
- Система полнотекствоого поиска.
- Аналитика пользователей и ссылок.
- SEO, meta-поля, URL-Permalinks.

## 🛒🏷️eCommerce
- Extends CMS functionality.
- Products, Orders, Checkout, Stock.
- Variants, Payments, Shipping, Cart.
- Promotions, Coupons, Banners.

## Консоль
<img src="./docs/Images/Console.png">

## Тестирование
<img src="./docs/Images/Testing.png">

## Профайлер
<img src="./docs/Images/Profiler.png">