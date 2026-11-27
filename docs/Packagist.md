# Packagist

Packagist - это собственный менеджер зависимостей со встроенным загрузчиком 
классов и их кэшированием в файл. У каждого дочернего пакета должен быть свой 
package.json, в котором он задаёт свои зависимости.

## Инициализация

```bash 
php packagist.phar init
````

При инициализации он тут же создаёт по пути `packages` первый базовый 
пакет `Autoloader` через гитхаб ссылку вшитую в сам `packagist.phar` 
как отдельный пакет со своим `package.json`. Сам `packagist.phar` 
будет скачиваться в одну строчку через `curl` с официального 
репозитория.

## Технология

`php packagist.phar install`

Берётся мастер-ссылка, это будет ссылка на гитхаб пейджу, в которой будет
маппинг всех доступных зависимостей этого пакетного менеджера. Это наш
первичный реквест. Дальше мы парсим и находим там нашу зависимость и её
ссылку на репозиторий. Далее мы делаем вторичный реквест, на получение
`package.json` из репозитория чтобы проверить версию и найти ссылку на
скачивание зип архива. Далее делаем третичный запрос прямо на архив
и качаем его во временную папку. Распаковываем его в папку проекта в
`./packages/{имя-пакета}`. Далее оформляем его автозагрузку глядя на
блок `autoload` из его `package.json`. После этого проверяем все его
зависимости и рекурсивно проделываем тоже самое для мастер-проекта
откуда был запущен `packagist.phar`. Так мы получим все зависимости
в свою папку и сделаем для них автозагрузку. Любой этап можно и даже
желательно оптимизировать, потому что сейчас алгоритм линеен. 

Ко всему прочему рядом с проектом создаётся `package.lock` и
создаёт скомпилированную карту зависимостей которая лежит в
`./packages/Autoload/Cache/AutoloadCacheMap.php`. Все последующие 
выполнения этой комманды будут опираться строго на лок-файл.

## Установка зависимости

```bash 
php packagist.phar require neo
````

Обновит `package.json`, `package.lock`, и кэш-карту загрузки.

## Удаление зависимости

```bash 
php packagist.phar remove neo
````

Обновит `package.json`, `package.lock`, и кэш-карту загрузки.

## Обновление

```bash 
php packagist.phar update
````

Происходит всё тоже самое что и при установке, с разницей: обновляемый 
пакет перемещается во временную папку для будущего роллбека в случае 
неудачи, а на его место распаковывается свежий архив.

## Оптимизиация

```bash 
php packagist.phar optimize
````

Обновляет скомпилированную карту зависимостей которая лежит в 
`./packages/Autoload`.

## package.json

Это громоздкий пример зависимости.

```json 
{
  "info": {
    "name": "neo",
    "version": "3.0.0",
    "author": "wemmbley",
    "website": "https://github.com/wemmbley/neo",
  },
  "autoload":  {
    "classes": {
      "Neo": "./src"
    },
    "files": [
      "./src/Foundation/Kernel/helpers.php",
      "./src/Foundation/Config/helpers.php",
      "./src/Diagnostic/Debug/Helpers.php",
      "./src/Diagnostic/Testing/Helpers.php",
      "./src/Information/Lang/Helpers.php",
      "./src/Information/Collection/Helpers.php",
      "./src/Network/Http/Helpers.php",
      "./src/Security/Helpers.php",
      "./src/Presentation/View/Helpers.php"
    ]
  },
  "php": {
    "version": ">=8.5",
    "extensions": {
      "required": {
        "json": "Команда config:show, вывод конфигов",
        "mbstring": "mb_* в командах, кириллица",
        "pcre": "Разбор Env и сигнатур команд",
        "Reflection": "Автосборка зависимостей контейнером",
        "openssl": "Security домен, Crypt сервис",
        "zip": "Скачивание и распаковка пакетов с Git",
        "spl": "spl_autoload_register"
      },
      "recommended": {
        "curl": "HTTP клиент",
        "sockets": "UDP клиент и другие протоколы"
      }, 
      "oneOf": [
        {
          "pdo_sqlite": "Базовый драйвер для моделей",
          "pdo_mysql": "Дополнительный драйвер для моделей"
        }
      ],
    },
    "functions": {
      "recommended": {
        "proc_open": "Swoole Hooks.",
        "symlink": "Storage Symlinks.",
        "ini_set": "Tests, timezones."
      }
    }
  },
  "packages": {
    "neo/autoload": ">= 1.0.0"
  }
}
```

А это пример мастер-пекеджа в корне проекта. Этот проект не является 
будущей зависимостью, поэтому часть блоков убрана за ненадобностью.

```json 
{
  "info": {
    "name": "App",
    "version": "1.0.0",
    "author": "wemmbley",
    "website": "https://github.com/wemmbley",
  },
  "autoload":  {
    "classes": {
      "App": "./app",
      "Src": "./src"
    }
  },
  "packages": {
    "neo": ">=3.0"
  }
}
```

Структура идентична, разница лишь в том, что второй подключает первый.

Можно так же свои реестры зависимостей добавлять:
```json 
{
  "info": {
    "name": "App",
    "version": "1.0.0",
    "author": "wemmbley",
    "website": "https://github.com/wemmbley",
  },
  "registry": [
    "https://github.com/wemmbley/packagist/registry.json"
  ],
  "autoload":  {
    "classes": {
      "App": "./app",
      "Src": "./src"
    }
  },
  "packages": {
    "neo": ">=3.0"
  }
}
```

Реестр работает только для мастер-пакета и является крайне нежелательным.
Поэтому используя свой реестр всегда нужно светить плашкой о небезопасности 
потенциально скачиваемых пакетов. Плашку отключить можно командой:

```bash 
php packagist.phar registry:warning disable
````

В `package.json` это добавит специальную ссылку в блок "registry": 
"disable-warnings" - это значит плашку отключаем, и не парсим этот 
текст как ссылку на репозиторий.

Так же можно консолью добавить новый реестр:

```bash 
php packagist.phar registry add https://github.com/wemmbley/packagist/registry.json
````

## Формат реестра:

```json 
{
  "type": "registry",
  "packages": {
    "neo": {
      "mirrors": ["https://github.com/wemmbley/neo"],
      "versions": {
        "3.0.0": {
          "dist": {"url": "https://codeload.github.com/wemmbley/neo/zip/refs/tags/3.x", "sha256": "..."},
          "require": {"database": ">=1.0", "security": ">=1.0"},
          "php": ">=8.5"
        }
      }
    }
  }
}
```

Как мы видим здесь в порядке приоритета лежат зеркала на официальные 
репозитории зависимостей. Если первая ссылка не работает, берем вторую. 
Если не сработали все - оповещаем в консоль как критикал ошибку. 

Версионирование происходит но основе бренчей гит-репозитория и поиска 
в них `package.json`.

## Ошибки

Если запрашиваемая версия не найдена, консоль об этом сообщит ярко 
красной табличкой. Если при распаковке произошла ошибка - должен быть 
откат и сообщение об этом.

## Кэширование

Необходимо продумать "по умному" как кэшировать файлы и папки скачиваемых 
модулей для более быстрой загрузки. Хотя и `spl_` работает достаточно быстро.