## Порядок запуска

`Kernel::bootstrap()` строго по порядку:

1. **EnvBootstrapper**: читает `.env` (настоящее окружение сервера сильнее файла);
2. **ConfigBootstrapper**: читает `core/config/*.php` (`env()` уже работает), кладёт `Config` в контейнер,
   при `app.profiler = true` включает профилировщик;
3. **ConsoleBootstrapper**: лениво привязывает `Output` и `CommandProcessor`
   (встроенные команды + `console.commands`);
4. Затем регистрируются провайдеры из `app.providers`.
5. `boot()` провайдеров вызывается в `run()` (web) или при запуске любой команды, кроме `help`, `config:show`, `env:set`.

#### Отложенные (ленивые) провайдеры:

Реализуйте `DeferrableProviderContract` и регистрируйте именем класса: провайдер создастся
только при первом запросе его сервиса, до этого он создаваться не будет. Полезно для
сильно нагруженных частей приложения. В обиходе не используется.

`loadRoutes/loadViews/loadTranslations/loadMigrations/publish` запоминают заявки в
`Kernel::resources('routes'|'views'|...)`: их читают соответствующие модули.