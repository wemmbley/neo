# Neo: спецификация Database и ORM

Nov 23, 2026

## Обзор

Neo Database — слой работы с БД (соединения, Query Builder, схема, миграции, сидеры), Neo ORM — Eloquent-подобные модели поверх него. Поддерживаются sqlite и MySQL, целевая версия PHP 8.5. Database работает без ORM; ORM без Database не работает.

| Часть | Пространство имён | Что внутри |
| --- | --- | --- |
| Database | `Neo\Information\Persistence\Database` | `DatabaseManager`, `Connection`, `Query\Builder`, `Schema\*`, `Migrations\*`, `Seeding\Seeder`, `Console\*`, фасады `DB` и `Schema` |
| Декларативные миграции | `…\Database\Schema\Declarative` | `SchemaMigrator`, `Recorder`, `SchemaComparator`, `Introspector`, `DdlBuilder`, `SqliteRebuilder`, `Operation` |
| ORM | `Neo\Information\Persistence\ORM` | `Model`, `Builder`, `EagerLoader`, `Metadata`, `Attributes`, `Relations`, `Casts`, `Factories`, `Events` |

Зависимости от остального Neo: `ServiceProvider` и контейнер (Foundation\\Kernel), фасады (`Facade`, `FacadeOf`), `EventDispatcher`, коллекции и пагинаторы (`Neo\Information\Collection`), `Validator` (`Neo\Information\Validator`), консольные команды (`Neo\Foundation\Console\Commands\Command`).

Принципы:

- Модель описывается двумя равноправными способами: классическими свойствами (`$fillable`, `$casts`, `$table`) или атрибутами PHP (`#[DB]`, `#[Column]`, `#[Rules]`). Способы можно смешивать, атрибут сильнее свойства.
- Структура БД меняется двумя равноправными способами: legacy-миграциями (`up()`/`down()`) и декларативными (`table()`): один файл выбирает один способ.
- Расширение таблиц модулями (`Schema::extend`) обязательно для движка: у таблицы один владелец, расширять её может любое число модулей.

## Подключение и конфигурация

Подключение делают три провайдера в `app.providers` и конфиг `app/Config/database.php`; без него работают значения по умолчанию из `Database/config.php`.

| Провайдер | Зачем |
| --- | --- |
| `Neo\Foundation\Kernel\Events\EventServiceProvider` | диспетчер событий (нужен ORM) |
| `Neo\Information\Persistence\Database\DatabaseServiceProvider` | `DatabaseManager`, `SchemaBuilder`, `Migrator`, `SchemaMigrator`, команды консоли |
| `Neo\Information\Persistence\ORM\ORMServiceProvider` | подключает `Model` к соединениям, диспетчеру и `FactoryManager` |

Фасады (наследуют корневой `Neo\Foundation\Kernel\Facade`): `Database\Facades\DB`, `Database\Facades\Schema`, `ORM\Facades\Factory`, `Foundation\Kernel\Facades\Event`.

Структура `database.php`:

| Ключ | Что задаёт | По умолчанию |
| --- | --- | --- |
| `default` | имя соединения по умолчанию | `env('DB_CONNECTION', 'sqlite')` |
| `connections.<name>` | настройки соединения; `driver` = `sqlite` или `mysql` | два готовых: `sqlite`, `mysql` |
| `connections.*.prefix` | префикс имён таблиц (применяется в `table()` и Schema) | `''` |
| `migrations.table` | журнал legacy-миграций | `migrations` |
| `migrations.commits_table` | история декларативных миграций | `migrations_commits` |
| `migrations.connection` | соединение для миграций | `env('DB_MIGRATION_CONNECTION')`, иначе `default` |
| `migrations.paths` | папки с миграциями приложения | `[base_path('app/Database/Migrations')]` |
| `seeder` | корневой сидер для `db:seed` | `Database\Seeders\DatabaseSeeder` |
| `factories_namespace` | пространство имён фабрик по умолчанию | `Database\Factories\` |

Соединения:

- **sqlite**: `database` (путь к файлу или `:memory:`), `foreign_key_constraints` (по умолчанию `true`), `busy_timeout`, `journal_mode`, `synchronous`, `transaction_mode`, `timezone`.
- **mysql**: `url` (DSN-строка, разбирается в остальные поля), `host`, `port`, `database`, `username`, `password`, `unix_socket`, `charset` (`utf8mb4`), `collation` (`utf8mb4_unicode_ci`), `strict`, `engine`, `timezone`.
- Переменные окружения: `DB_CONNECTION`, `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `DB_URL`, `DB_SOCKET`, `DB_CHARSET`, `DB_COLLATION`, `DB_FOREIGN_KEYS`, `DB_MIGRATION_CONNECTION`.

Ловушка sqlite: если файла базы нет, коннектор создаёт пустой файл без ошибки. Относительный путь разрешается от текущей папки процесса, поэтому CLI и веб могут работать с разными файлами (отсюда «no such table»). Задавайте абсолютный путь через `base_path()`.

## Database: соединения и Query Builder

`DB::` — фасад над `DatabaseManager`. Менеджер хранит соединения по имени, всё, чего в нём нет, проксируется в соединение по умолчанию, поэтому `DB::table()`, `DB::select()`, `DB::transaction()` работают как в Laravel.

| Класс | Методы |
| --- | --- |
| `DatabaseManager` | `connection(?name)`, `getDefaultConnection()`, `setDefaultConnection(name)`, `purge(?name)`, `extend(name, config)`, `table(table)`, `schema(?connection)`, `raw(sql)` |
| `Connection` | `table`, `schema`, `raw`, `driver()`, `pdo()`, `withPrefix(table)`, `listen(Closure)`, `select`, `selectOne`, `statement`, `affectingStatement`, `insert`, `update`, `delete`, `unprepared`, `lastInsertId`, `beginTransaction`, `commit`, `rollBack`, `transactionLevel`, `transaction(Closure)` |

`select()` возвращает массив ассоциативных массивов, `selectOne()` — строку или `null`. Параметры передаются вторым аргументом привязками (`?`). `transaction(fn)` делает commit при успехе и rollback с перебросом исключения при ошибке; вложенность учитывается через `transactionLevel()`. `listen(fn)` подписывает на выполняемые запросы (логирование).

### Query Builder

`DB::table('users')` возвращает `Query\Builder`. Все цепочные методы возвращают `static`.

| Группа | Методы |
| --- | --- |
| Выборка | `select(...)`, `addSelect(...)`, `distinct()`, `table(name)` |
| Соединения | `join(table, a, op, b)`, `leftJoin(...)` |
| Условия | `where`, `orWhere`, `whereColumn`, `whereIn`, `whereNotIn`, `whereNull`, `whereNotNull`, `whereBetween`, `whereRaw` |
| Группировка, порядок | `groupBy`, `orderBy`, `orderByDesc`, `latest(col)`, `oldest(col)` |
| Лимиты | `limit`/`take`, `offset`/`skip`, `forPage(page, perPage)` |
| Чтение | `get()`, `first()`, `find(id, key='id')`, `value(col)`, `pluck(col, ?key)`, `exists()` |
| Агрегаты | `count`, `max`, `min`, `sum`, `avg` |
| Запись | `insert(array)`, `insertGetId(array)`, `update(array)`, `increment(col, amount, extra)`, `delete()` |
| Служебные | `toSql()`, `getBindings()`, `newQuery()` |

Правила `where`:

- `where('a', 5)` — равенство; `where('a', '>', 5)` — с оператором; `where(['a' => 1, 'b' => 2])` — массив равенств через AND; `where(fn (Builder $q) => …)` — группа в скобках.
- Значение `null` превращается в `IS NULL` (для оператора, отличного от `=`, — в `IS NOT NULL`).
- Неизвестный оператор бросает `InvalidArgumentException`.
- Непосредственный SQL — только через `whereRaw(sql, bindings)` или `DB::raw(...)`; значения всегда уходят привязками.

```php
use Neo\Information\Persistence\Database\Facades\DB;

$id = DB::table('users')->insertGetId(['name' => 'Ann', 'email' => 'a@x.test']);

$rows = DB::table('users')
    ->where('active', 1)
    ->where(fn ($q) => $q->where('role', 'admin')->orWhere('role', 'editor'))
    ->orderByDesc('id')
    ->limit(20)
    ->get();

DB::transaction(function () use ($id) {
    DB::table('users')->where('id', $id)->update(['name' => 'Anna']);
    DB::table('logs')->insert(['user_id' => $id, 'action' => 'rename']);
});
```

Префикс таблиц (`connections.*.prefix`) добавляется автоматически в `table()` и в Schema.

## Schema: таблицы, колонки, индексы

`Schema::` — фасад над `SchemaBuilder` соединения по умолчанию на момент вызова. Описание таблицы даёт `Blueprint`, описание колонки — `ColumnDefinition` (модификаторы цепляются).

| Метод `Schema::` | Делает |
| --- | --- |
| `create(table, fn (Blueprint $t))` | создаёт таблицу |
| `table(table, fn)` | меняет существую: добавляет колонки и индексы, `dropColumn()` |
| `drop(table)`, `dropIfExists(table)`, `rename(from, to)` | удаляет / переименовывает таблицу |
| `hasTable`, `hasColumn`, `getColumnListing` | интроспекция (с учётом префикса) |
| `disableForeignKeyConstraints()`, `enableForeignKeyConstraints()` | переключают проверку внешних ключей |
| `extend(table, fn)` | только внутри `Migration::table()`: расширяет таблицу другого модуля (см. «Декларативные миграции») |

Типы колонок `Blueprint` и их SQL:

| Метод | sqlite | mysql |
| --- | --- | --- |
| `id(name='id')`, `bigIncrements` | `integer primary key autoincrement` | `bigint unsigned auto_increment`, PK |
| `increments(name)` | `integer primary key autoincrement` | `int unsigned auto_increment`, PK |
| `integer`, `bigInteger` | `integer` | `int`, `bigint` |
| `foreignId(name)`, `unsignedBigInteger(name)` | `integer` | `bigint unsigned` |
| `string(name, length=255)` | `varchar(n)` | `varchar(n)` |
| `text`, `json` | `text` | `text`, `json` |
| `boolean` | `tinyint(1)` | `tinyint(1)` |
| `float` | `float` | `double` |
| `decimal(name, total=10, places=2)` | `numeric(t, p)` | `decimal(t, p)` |
| `date`, `dateTime`, `timestamp` | `date`, `datetime`, `datetime` | `date`, `datetime`, `timestamp` |
| `timestamps()` | `created_at`, `updated_at` (nullable timestamp) | то же |
| `softDeletes(name='deleted_at')` | nullable timestamp | то же |
| `morphs(name)` | `{name}_id` + `{name}_type` + составной индекс | то же |

Модификаторы колонки (цепочкой): `nullable()`, `default(value)`, `useCurrent()`, `unsigned()`, `primary()`, `unique()`, `index()`, `comment(text)` (только MySQL), `renamedFrom('old')` (декларативный поток). Внешние ключи: `constrained(table, column='id')`, `cascadeOnDelete()`, `nullOnDelete()`, `cascadeOnUpdate()`.

Индексы таблицы: `index(cols, ?name)`, `unique(cols, ?name)`, `primary(cols)`. Имена по умолчанию: индекс `{table}_{cols}_{index|unique}`, внешний ключ `{table}_{column}_foreign`.

```php
Schema::create('posts', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $t->string('title');
    $t->text('body')->nullable();
    $t->boolean('published')->default(false);
    $t->timestamps();
    $t->index(['user_id', 'published']);
});
```

Ограничение legacy: `Schema::table()` не умеет менять тип и переименовывать колонки. Для этого есть декларативные миграции.

## Миграции legacy (up/down) и сидеры

Legacy-миграция — файл, который возвращает анонимный наследник `Migration` с методом `up()`. Стратегия выбирается по тому, какой метод переопределён в файле: `up()` — legacy, `table()` — декларативная. Оба метода в одном файле дают `DoubleStrategyException`, ни одного — `MigrationStrategyException`.

```php
<?php

declare(strict_types=1);

use Neo\Information\Persistence\Database\Facades\Schema;
use Neo\Information\Persistence\Database\Migrations\Migration;
use Neo\Information\Persistence\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
```

Правила:

- Имя файла `YYYY_MM_DD_HHMMSS_name.php`; порядок выполнения — по возрастанию имени файла без расширения.
- Журнал — таблица `migrations` (`id`, `migration`, `batch`). Каждый запуск `migrate` записывает все новые файлы одним батчем.
- `migrate:rollback` без параметров выполняет `down()` последнего батча в обратном порядке; если файла нет, бросает `DatabaseException`.
- `protected ?string $connection` в миграции — конкретное соединение (иначе `migrations.connection` или соединение по умолчанию).
- `make:migration name --create=table` и `--table=table` создают заготовку legacy-файла в первой папке `migrations.paths`.

Пути модулей добавляются через `Migrator::addPath($path, $owner = 'app')`: `$owner` — класс провайдера модуля. Для legacy владелец не влияет на поведение, для декларативных — входит в ключ истории.

### Сидеры

```php
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([UserSeeder::class]);
        Factory::make(Product::class)->count(10)->create();
    }
}
```

`Seeder::call(classes)` создаёт сидеры через контейнер (`kernel()`), если он есть. `db:seed` берёт класс из `--class=` или из `database.seeder`; `make:seeder Name` создаёт заготовку; `migrate:fresh --seed` запускает сидер после миграций.

## Декларативные миграции и Schema::extend

Файл с `table()` описывает желаемое состояние своих таблиц, а Neo сам считает разницу с прошлым состоянием этого файла, применяет её и пишет коммит в таблицу `migrations_commits`. `down()` не нужен: откат ведётся по истории. Стратегии `up()` и `table()` в одном файле не совмещаются.

```php
// Модуль Auth (владелец users)
return new class extends Migration
{
    public function table(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
        });
    }
};

// Модуль Telegram (расширяет users и создаёт свою таблицу)
return new class extends Migration
{
    public function table(): void
    {
        Schema::extend('users', fn (Blueprint $t) => $t->string('telegram_id')->nullable());

        Schema::create('tg_chats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
    }
};
```

### Что можно внутри `table()`

- Только `Schema::create(...)` и `Schema::extend(...)`; выполняется в режиме записи: ничего не исполняется, Neo лишь собирает описание. Таблиц в файле может быть несколько, но каждая — один раз.
- `Schema::table()`, `drop()`, `dropIfExists()`, `rename()` и `Blueprint::dropColumn()` бросают `LogicException`: колонку убирают, удаляя её из описания.
- В `extend` нельзя объявлять `id()`, `increments()`, `bigIncrements()`, `primary()`: первичный ключ принадлежит создателю таблицы.
- Префиксы в именах колонок не нужны: защита от коллизий — строгий режим.

### Владение и коллизии

Key файла = `owner::имя_файла`, где `owner` — класс провайдера модуля (`app` для приложения). Каждый файл владеет своим «вкладом» в таблицы: история и диффы считаются по вкладу, составная схема всех файлов нужна только для проверок. Файл может менять и удалять только своё.

| Ситуация | Результат |
| --- | --- |
| два файла делают `create` одной таблицы | `SchemaConflictException` |
| два файла объявляют одну колонку или одинаковое имя индекса | `SchemaConflictException` |
| `extend` таблицы, которую никто не создаёт и которой нет в БД | `SchemaConflictException` |
| удаление таблицы, в которой есть чужой вклад | `SchemaConflictException` |
| пропал файл модуля | колонки остаются, файл помечен `[Orphaned]`; удаляет только `migrate:purge` |

### Как работает `migrate`

1. Проходит legacy-файлы (`up()`), затем декларативные.
2. Для каждого декларативного файла считается sha1 содержимого и записывается вклад (`table()` выполняется как запись). Если хэш равен хэшу последнего коммита файла — пропуск.
3. Проверяется составная схема всех файлов (коллизии).
4. Считается разница между снимком прошлого коммита и новым вкладом. Если коммитов ещё нет, а таблица уже есть в БД, разница считается с реальной структурой (PRAGMA для sqlite, information\_schema для MySQL): только добавление и изменение объявленного, лишние колонки остаются с предупреждением. Так работает переход с legacy.
5. Двухфазное применение: сначала все `create` всех модулей (порядок по внешним ключам), потом остальные операции, сгруппированные по таблицам.
6. Разрушающие операции показываются выделенно и требуют подтверждения.
7. Применение и коммит на каждый изменённый файл (снимок + список операций).

### Операции

| Операция | Разрушающая? |
| --- | --- |
| `createTable`, `addColumn`, `addIndex`, `addForeign`, `dropIndex`, `dropForeign` | нет |
| `changeColumn` | да, если меняется SQL-тип; иначе нет |
| `renameColumn`, `dropColumn`, `dropTable` | да |

Индексы и внешние ключи сравниваются по форме (колонки, тип), не по имени; комментарий колонки в сравнении не участвует. Смена первичного ключа не поддерживается (`UnsupportedSchemaChangeException`).

### Переименование колонок

- Явно: `$t->string('mobile')->renamedFrom('phone')`; подсказку можно оставить в файле, лишняя никому не мешает.
- Интерактивно: если исчезла ровно одна колонка и появилась ровно одна того же SQL-типа, Neo спрашивает «это переименование?». Ответ «нет» даёт удаление и добавление.
- Вне терминала и при неоднозначности (несколько пар) — `ConfirmationRequiredException`, даже с `--yes`: нужен `renamedFrom`.

### История `migrations_commits`

| Колонка | Смысл |
| --- | --- |
| `id` | сквозной номер коммита (его использует `migrate:rollback --to`) |
| `owner`, `migration` | ключ файла |
| `action` | `apply`, `rollback` или `purge` |
| `hash` | sha1 файла на момент коммита |
| `snapshot` | JSON: состояние вклада после коммита |
| `operations` | JSON: применённые операции (с данными, нужными для обратной операции) |
| `created_at` | время |

### Откат и орфаны

- `migrate:rollback --to=ID [--file=имя]` возвращает структуру к состоянию после коммита `ID` (как `git reset`): операции коммитов после `ID` отменяются в обратном порядке, пишется коммит `rollback` на каждый затронутый файл.
- Откат возвращает **только структуру**, данные удалённых колонок и таблиц не восстанавливаются. Колонка `NOT NULL` без default восстанавливается как `NULL` с предупреждением. Откат добавления колонки удаляет её и требует подтверждения.
- После отката файл остаётся «новым»: следующий `migrate` применит его снова. Чтобы откатить навсегда, верните файл к прошлой версии (git).
- Орфан: файла нет, а его коммит есть. `migrate:purge` удаляет его колонки (для `create` — таблицу) с подтверждением и пишет коммит `purge`.

### Драйверы

- **sqlite**: простые операции (добавление nullable/default-колонки, индексы, `RENAME COLUMN`) — напрямую; `dropColumn`, `changeColumn`, внешние ключи и NOT NULL без default — пересборкой таблицы (новая таблица, копирование данных, подмена, индексы). Весь план — одна транзакция при `PRAGMA foreign_keys = OFF`; после пересборки выполняется `PRAGMA foreign_key_check`, при нарушении всё отменяется.
- **mysql**: прямые `ALTER TABLE` (`ADD/DROP COLUMN`, `MODIFY`, `CHANGE` для переименования, `DROP FOREIGN KEY`, `DROP INDEX`). DDL в MySQL коммитится неявно, поэтому частично применённый план откатить нельзя; перед первым запуском используйте `--pretend`.

## Консольные команды

Команды наследуют `Neo\Foundation\Console\Commands\Command` и регистрируются в `DatabaseServiceProvider`. Код выхода: `0` — успех, `1` — ошибка или отмена, `2` — неверный ввод.

| Сигнатура | Что делает |
| --- | --- |
| `migrate [--pretend] [--yes]` | сначала legacy (`up()`), затем декларативные; `--pretend` показывает план и SQL без выполнения; `--yes` заранее подтверждает разрушающие операции |
| `migrate:status` | legacy: `[Ran]`/`[Pending]`; декларативные: `[Applied]`/`[Changed]`/`[Pending]` + номер коммита; орфаны `[Orphaned]` |
| `migrate:log [--file=имя]` | история коммитов с операциями (включая переименования и удаления) |
| `migrate:rollback` | откат последнего legacy-батча (`down()`) |
| `migrate:rollback --to=ID [--file=имя] [--pretend] [--yes]` | декларативный откат структуры к коммиту `ID`; `--file` без `--to` — ошибка (код 2) |
| `migrate:purge [--pretend] [--yes]` | удаляет структуру орфанов (миграций без файла) |
| `migrate:fresh [--seed]` | удаляет ВСЕ таблицы (включая журналы) и применяет оба потока заново; `--seed` запускает сидер |
| `make:migration name [--create=table] [--table=table]` | заготовка legacy-миграции |
| `make:seeder Name` | заготовка сидера |
| `db:seed [--class=Class]` | запускает сидер |

Подтверждения:

- В терминале разрушающие операции показываются жёлтым блоком и требуют ответа `y`/`yes`/`д`/`да`; любой другой ответ — отмена, ничего не изменено.
- Без терминала (cron, CI, пайп) и без `--yes` такие операции отклоняются с кодом 1 и списком операций.
- `--pretend` ничего не меняет; для sqlite-пересборок показывает описание вместо полного SQL.

Пример рабочего цикла:

```bash
php cli migrate --pretend        # посмотреть, что будет
php cli migrate                  # применить
php cli migrate:log              # увидеть номера коммитов
php cli migrate:rollback --to=12 # вернуться к коммиту 12
```

## ORM: определение модели

Модель — наследник `Neo\Information\Persistence\ORM\Model`. Её описание читается один раз через рефлексию в `ModelMetadata` и кешируется на класс. Колонка базы = **публичное типизированное свойство** модели; колонки без свойства доступны как динамические атрибуты (`$model->column`).

### Способ 1: классический

```php
final class User extends Model
{
    protected ?string $table = 'users';        // по умолчанию: множественное snake_case от имени класса
    protected ?string $connection = null;      // по умолчанию: соединение по умолчанию
    protected string $primaryKey = 'id';
    protected bool $incrementing = true;
    protected bool $timestamps = true;         // created_at / updated_at
    protected array $fillable = ['name', 'email'];
    protected array $guarded = [];
    protected array $hidden = ['password'];
    protected array $casts = ['active' => 'boolean', 'settings' => 'json'];
    protected array $with = ['profile'];       // грузить отношения всегда

    public int $id;
    public string $name;
    public string $email;
    public string $password;
    public bool $active = true;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
```

### Способ 2: атрибуты

```php
#[DB(table: 'users', timestamps: true)]
#[HasMany(Post::class)]
final class User extends Model
{
    #[PrimaryKey] public int $id;
    #[Rules(['required', 'string'])] public string $name;
    #[Column('mail')] #[Rules(['required', 'email'])] public string $email;
    #[Hidden] public string $password;
    #[Cast('boolean')] #[Column(default: true)] public bool $active;
    #[Immutable] public ?string $uuid = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
```

Вторая написанная версия `#[Config(...)]` — один атрибут вместо нескольких: `#[Config(fillable: true, hidden: false, immutable: false, cast: 'float', column: 'price_value', default: 0.0, rules: ['numeric'], messages: [...], watch: PriceChanged::class)]`.

### Приоритеты

Атрибут сильнее классического свойства, свойство — сильнее умолчания:

| Что | Порядок определения (первое сработавшее побеждает) |
| --- | --- |
| таблица | `#[DB(table)]` → `$table` → plural(snake(имя класса)) |
| соединение | `#[DB(connection)]` → `$connection` → по умолчанию |
| timestamps | `#[DB(timestamps)]` → `$timestamps` → `true` |
| первичный ключ | `#[PrimaryKey]` на свойстве → `$primaryKey` → `id` |
| имя колонки | `#[Column(name)]` → `#[Config(column)]` → имя свойства |
| cast | `#[Cast]` → `#[Config(cast)]` → `$casts[property]` → выведенный из типа свойства |
| fillable | `#[Fillable]` → `#[Config(fillable)]` → `$fillable` (разрешает только перечисленное) → `$guarded` (запрещает перечисленное) → всё, кроме автоинкрементного ключа |
| hidden | `#[Hidden]` → `#[Config(hidden)]` → `$hidden` |
| default | `#[Column(default)]` → `#[Config(default)]` → значение свойства в классе |
| rules / messages | `#[Rules]` → `#[Config(rules, messages)]` |

Базовые методы экземпляра: `fill`, `forceFill`, `isFillable`, `setAttribute`, `getAttribute`, `getAttributes`, `getOriginal`, `getDirty`, `isDirty`, `syncOriginal`, `save`, `update`, `delete`, `refresh`, `fresh`, `toArray`, `toJson`, `getTable`, `getKey`, `getKeyName`. Статические: `query`, `all`, `find`, `findOrFail`, `create`, `destroy`, `factory`, `validate`, `validator`, `listen`, `observe`. Всё остальное из Query Builder проксируется в `Model::where(...)`, `Model::orderBy(...)` и т. д.

Ключевые поведения:

- `save()` делает INSERT для новой модели и UPDATE только изменённых полей; `created_at`/`updated_at` ставятся автоматически.
- `Model::all()` и запросы возвращают `Neo\Information\Collection\Collection` (заменить класс: `Model::useCollection(MyCollection::class)`, у класса должен быть статический `make(array)`).
- `toArray()`/`toJson()` скрывают `hidden`-поля и включают загруженные отношения.
- `#[Immutable]`-поле нельзя менять у уже сохранённой модели (`ImmutableAttributeException`).

## ORM: атрибуты, касты, валидация, #\[Watch\]

Все атрибуты лежат в `Neo\Information\Persistence\ORM\Attributes`, атрибуты отношений — в `…\Attributes\Relations`.

| Атрибут | Цель | Параметры |
| --- | --- | --- |
| `#[DB]` | класс | `connection`, `table`, `timestamps` |
| `#[PrimaryKey]` | свойство | `autoIncrement = true` |
| `#[Column]` | свойство | `name` (имя колонки), `default` |
| `#[Cast]` | свойство | `type` |
| `#[Fillable]`, `#[Hidden]`, `#[Immutable]` | свойство | `value = true` |
| `#[Rules]` | свойство | `rules`, `messages` |
| `#[Watch]` | свойство | `event` (класс события) |
| `#[Config]` | свойство | `fillable`, `hidden`, `immutable`, `cast`, `column`, `default`, `rules`, `messages`, `watch` |
| `#[ObservedBy]` | класс (повторяемый) | `observers` (класс или массив классов) |
| `#[UseFactory]` | класс | `factory` |

### Касты

Каст преобразует значение из базы в тип свойства. Не задан явно — выводится из типа свойства (`int`, `float`, `string`, `bool`, `array`, backed enum, `DateTimeInterface`).

| Строка каста | Результат |
| --- | --- |
| `int` / `integer` | `int` |
| `float` / `double` / `real` | `float` |
| `string` | `string` |
| `bool` / `boolean` | `bool` (`'0'`, `'false'`, `''` → `false`) |
| `array` / `json` | массив из JSON |
| `object` | `stdClass` из JSON |
| `decimal:2` | число с указанным числом знаков |
| `datetime` / `timestamp`, `immutable_datetime` | объект даты (класс выбирает `DateCaster`: Chronos из Neo, если он есть, иначе встроенный) |
| `date`, `immutable_date` | то же, время обнулено |
| имя класса enum или класса даты | соответствующий объект |

Для классических моделей касты задаются в `$casts = ['field' => 'boolean']` и работают даже для колонок без свойства.

### Валидация

Правила из `#[Rules]` не выполняются при `save()` сами — их вызывают явно (в контроллере, сервисе):

- `Model::validationRules()` → `[property => rules]`, `Model::validationMessages()` → `[property => [rule => text]]`.
- `Model::validator(array $data)` → `Neo\Information\Validator\Validator` (данные ключуются именами свойств, сообщения передаются как `property.rule`).
- `Model::validate(array $data)` → массив только полей с правилами, или `ValidationException`.

```php
$data = Product::validate($request->all());   // ValidationException, если неверно
$product = Product::create($data);
```

### #\[Watch\]

`#[Watch(PriceChanged::class)]` на свойстве диспетчит объект события через `EventDispatcher` **после `updated`, если поле изменилось** (при INSERT не срабатывает). Конструктор класса события заполняется по типу или имени параметра, порядок не важен:

| Параметр | Что получает |
| --- | --- |
| тип модели или имя `model` | сама модель |
| `property` | имя свойства |
| `old`, `oldValue`, `previous` | старое значение (с кастом) |
| `new`, `newValue`, `value`, `current` | новое значение |

```php
final class PriceChanged
{
    public function __construct(public Product $product, public float $old, public float $new) {}
}

#[Config(cast: 'float', watch: PriceChanged::class)] public float $price;

Event::listen(PriceChanged::class, fn (PriceChanged $e) => /* … */);
```

Неизвестный обязательный параметр конструктора → `InvalidArgumentException`. `Model::watchedProperties()` возвращает `[property => событие]`.

## ORM: отношения, eager loading, N+1

Отношения объявляются **атрибутами на классе модели** (повторяемые), методов `hasMany()` в модели нет. Имя отношения — параметр `name`; если он не задан, это camelCase имени связанного класса (для «многих» — во множественном числе): `HasMany(Comment::class)` → `comments`.

```php
#[HasMany(Comment::class)]
#[BelongsTo(User::class, name: 'author', foreignKey: 'author_id')]
#[BelongsToMany(Tag::class)]
#[MorphMany(Image::class, 'imageable')]
final class Post extends Model { /* … */ }
```

| Атрибут | Параметры | Ключи по умолчанию |
| --- | --- | --- |
| `HasOne`, `HasMany` | `related`, `name`, `foreignKey`, `localKey` | `foreignKey` = `{snake(родитель)}_id` в связанной таблице; `localKey` = первичный ключ |
| `BelongsTo` | `related`, `name`, `foreignKey`, `ownerKey` | `foreignKey` = `{snake(name)}_id` у себя |
| `BelongsToMany` | `related`, `name`, `table`, `foreignPivotKey`, `relatedPivotKey`, `pivotModel`, `pivotColumns` | таблица = два имени моделей snake, по алфавиту, через `_` (`post_tag`); ключи `{model}_id` |
| `HasOneThrough`, `HasManyThrough` | `related`, `through`, `name`, `firstKey`, `secondKey`, `localKey`, `secondLocalKey` | `firstKey` = `{snake(родитель)}_id`; `secondKey` = `{snake(through)}_id` |
| `MorphOne`, `MorphMany` | `related`, `morphName`, `name` | колонки `{morphName}_id` и `{morphName}_type` в связанной таблице |
| `MorphTo` | `name` | колонки `{name}_id`, `{name}_type` у себя; в `_type` — имя класса модели, допускаются только подклассы `Model` |
| `MorphToMany`, `MorphedByMany` | `related`, `morphName`, `name`, `table` | таблица = plural(`morphName`) |

### Доступ

- `$post->comments` — результат (коллекция или модель/`null`), грузится один раз и кешируется в модели.
- `$post->comments()` — объект `Relation`: запрос с ограничениями, к нему можно добавлять `where()`, `orderBy()` и т. д. (вызовы проксируются в построитель).
- Запись: `BelongsTo::associate(Model $owner)`; `BelongsToMany::attach(ids, extra)`, `detach(ids = null)`, `sync(ids)`. Поля связочной таблицы доступны в `$tag->pivot`.
- `Model::relation(name)`, `relationLoaded(name)`, `getRelation(name)`, `setRelation(name, value)`.
- Обращение к несуществующему отношению через `with()`/`load()`/`relation()` — `LogicException` «не имеет отношения».

### Eager loading

На каждое отношение и каждый уровень вложенности — **один запрос** независимо от числа моделей.

```php
$posts = Post::with('author', 'comments.user', ['tags' => fn ($q) => $q->where('active', 1)])->get();

$post->load('comments');          // догрузить
$post->loadMissing('comments');   // только если ещё не загружено
Post::without('comments')->get(); // убрать отношение из $with
Post::withoutEagerLoads()->get(); // не грузить ничего
```

- Формат: `'a'`, `'a.b'` (вложенное), `'a' => fn (Builder $q) => …` (ограничение), массивы тоже принимаются.
- `protected array $with = ['author']` грузит отношения при каждом запросе модели.
- `MorphTo` грузится группами по классу в `_type`.
- Родители без значения ключа (null FK) не дают «чужих» строк: запрос строится с условием `0 = 1`.

### Защита от N+1

```php
// в boot() провайдера, обычно только в dev
Model::preventLazyLoading();

// вместо исключения — запись в лог
Model::handleLazyLoadingViolationUsing(fn (Model $m, string $relation) => logger()->warning(…));
```

Если включено, ленивый доступ `$model->relation` к незагруженному отношению модели, **полученной из запроса**, бросает `LazyLoadingViolationException`. Модели, созданные в коде (`new`/`create`), под запрет не попадают. Вызов `$model->relation()` (объект-запрос) и `load()` разрешены.

### Пагинация

`Post::query()->paginate(15)` возвращает `LengthAwarePaginator`, `simplePaginate(15)` — `Paginator` из `Neo\Information\Collection`; страница и путь берутся из `resolveCurrentPage()`/`resolveCurrentPath()` пагинатора. Сигнатура: `paginate(perPage = 15, ?page, options = [], pageName = 'page')`. Классы пагинаторов меняются через `Builder::usePaginators($lengthAware, $simple)`.
