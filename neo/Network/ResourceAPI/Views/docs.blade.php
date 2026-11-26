<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Документация API</title>
    <script src="{{ resource('JS/Quas.js') }}"></script>
</head>
<body class="bg-white text-zinc-800 dark:bg-zinc-950 dark:text-zinc-200 text-[15px] leading-6">

<template id="app">
    <div class="flex min-h-screen max-md:block">

        {{-- Навигация --}}
        <nav class="w-60 shrink-0 border-r border-zinc-200 dark:border-zinc-800 p-3 md:sticky md:top-0 md:h-screen overflow-auto max-md:w-auto max-md:border-r-0 max-md:border-b">
            <h1 class="px-2 pb-3 text-sm font-semibold">API</h1>

            <button class="block w-full text-left px-2 py-1.5 rounded-md hover:bg-zinc-100 dark:hover:bg-zinc-900"
                    w-for="r in docs.resources traceby r.name"
                    w-class="{ 'bg-zinc-100 dark:bg-zinc-900 text-blue-600': page == r.name }"
                    w-on="click:open(r.name)">@{{ r.name }}</button>

            <button class="block w-full text-left px-2 py-1.5 rounded-md hover:bg-zinc-100 dark:hover:bg-zinc-900"
                    w-class="{ 'bg-zinc-100 dark:bg-zinc-900 text-blue-600': page == 'query' }"
                    w-on="click:open('query')">Запросы</button>
        </nav>

        <main class="flex-1 min-w-0 max-w-4xl px-8 py-7 max-md:px-4">

            <p class="text-zinc-500" w-if="!docs.resources.length && page != 'query'">Ресурсов нет.</p>

            {{-- Ресурс --}}
            <section w-if="resource">
                <h2 class="text-2xl font-semibold">@{{ resource.name }}</h2>
                <p class="mb-2 font-mono text-sm text-zinc-500">@{{ resource.url }}</p>

                <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Маршруты</h3>
                <table class="w-full text-left">
                    <tr class="border-b border-zinc-200 dark:border-zinc-800" w-for="e in resource.endpoints traceby e.key">
                        <td class="py-1.5 pr-3 w-20 font-mono text-sm font-bold"
                            w-class="{ 'text-emerald-600': e.method == 'GET',
                                       'text-amber-600': e.method == 'POST' || e.method == 'PUT' || e.method == 'PATCH',
                                       'text-red-600': e.method == 'DELETE' }">@{{ e.method }}</td>
                        <td class="py-1.5 pr-3 font-mono text-sm">@{{ e.uri }}</td>
                        <td class="py-1.5 text-zinc-500">@{{ e.action }}</td>
                    </tr>
                </table>

                <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Поля</h3>
                <table class="w-full text-left">
                    <tr class="text-xs text-zinc-500">
                        <th class="py-1.5 pr-3 font-semibold">Поле</th>
                        <th class="py-1.5 pr-3 font-semibold">Тип</th>
                        <th class="py-1.5 pr-3 font-semibold">Фильтр</th>
                        <th class="py-1.5 pr-3 font-semibold">Сортировка</th>
                        <th class="py-1.5 pr-3 font-semibold">Поиск</th>
                        <th class="py-1.5 font-semibold">Запись</th>
                    </tr>
                    <tr class="border-b border-zinc-200 dark:border-zinc-800" w-for="f in resource.schema.fields traceby f.name">
                        <td class="py-1.5 pr-3 font-mono text-sm">@{{ f.name }}<span class="text-zinc-500" w-if="f.primary"> (pk)</span></td>
                        <td class="py-1.5 pr-3 text-zinc-500">@{{ f.type }}<span w-if="f.nullable">?</span></td>
                        <td class="py-1.5 pr-3" w-class="{ 'text-emerald-600': f.filterable, 'text-zinc-400': !f.filterable }">@{{ f.filterable ? 'да' : '—' }}</td>
                        <td class="py-1.5 pr-3" w-class="{ 'text-emerald-600': f.sortable, 'text-zinc-400': !f.sortable }">@{{ f.sortable ? 'да' : '—' }}</td>
                        <td class="py-1.5 pr-3" w-class="{ 'text-emerald-600': f.searchable, 'text-zinc-400': !f.searchable }">@{{ f.searchable ? 'да' : '—' }}</td>
                        <td class="py-1.5" w-class="{ 'text-emerald-600': f.writable, 'text-zinc-400': !f.writable }">@{{ f.writable ? 'да' : '—' }}</td>
                    </tr>
                </table>

                <div w-if="resource.schema.include.length">
                    <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">include</h3>
                    <span class="inline-block mr-1 mb-1 px-2 rounded-full bg-zinc-100 dark:bg-zinc-900 font-mono text-xs"
                          w-for="i in resource.schema.include traceby i">@{{ i }}</span>
                </div>

                <div w-if="resource.schema.filters.length">
                    <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Кастомные фильтры</h3>
                    <span class="inline-block mr-1 mb-1 px-2 rounded-full bg-zinc-100 dark:bg-zinc-900 font-mono text-xs"
                          w-for="i in resource.schema.filters traceby i">filters[@{{ i }}]</span>
                </div>

                <div w-if="resource.relations.length">
                    <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Связи</h3>
                    <table class="w-full text-left">
                        <tr class="border-b border-zinc-200 dark:border-zinc-800" w-for="x in resource.relations traceby x.name">
                            <td class="py-1.5 pr-3 font-mono text-sm">@{{ x.name }}</td>
                            <td class="py-1.5 text-zinc-500"><span w-if="x.many">много</span><span w-if="!x.many">одна</span> → @{{ x.resource }}</td>
                        </tr>
                    </table>
                </div>

                <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Клиент</h3>
                <pre w-code="{ lang: 'js', code: example, title: 'neo-api.js' }"></pre>

                <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Попробовать</h3>
                <div class="flex gap-2">
                    <input class="flex-1 px-3 py-2 rounded-md border border-zinc-300 dark:border-zinc-700 bg-transparent font-mono text-sm"
                           w-bind="url">
                    <button class="px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50"
                            w-disabled="loading" w-on="click:send">GET</button>
                </div>
                <pre class="mt-2" w-if="response"
                     w-code="{ lang: 'json', code: response.body, title: response.title, numbers: true }"></pre>
            </section>

            {{-- Параметры запроса --}}
            <section w-if="!resource">
                <h2 class="text-2xl font-semibold mb-2">Параметры запроса</h2>

                <table class="w-full text-left">
                    <tr class="border-b border-zinc-200 dark:border-zinc-800" w-for="p in params traceby p.q">
                        <td class="py-1.5 pr-4 font-mono text-sm align-top">@{{ p.q }}</td>
                        <td class="py-1.5">@{{ p.d }}</td>
                    </tr>
                </table>

                <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Операторы</h3>
                <span class="inline-block mr-1 mb-1 px-2 rounded-full bg-zinc-100 dark:bg-zinc-900 font-mono text-xs"
                      w-for="o in docs.query.operators traceby o">@{{ o }}</span>

                <p class="mt-4 text-zinc-500">
                    Глубина связей: @{{ docs.query.relation_deep }}.
                    Условий в запросе: до @{{ docs.query.limits.max_conditions }},
                    элементов в $in: до @{{ docs.query.limits.max_in_items }},
                    длина значения: до @{{ docs.query.limits.max_value_length }}.
                </p>

                <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Запрос</h3>
                <pre w-code="{ lang: 'js', code: query, title: 'neo-api.js' }"></pre>

                <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Ответ</h3>
                <pre w-code="{ lang: 'json', code: envelope, title: '200 OK', numbers: true }"></pre>

                <h3 class="mt-7 mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Ошибка</h3>
                <pre w-code="{ lang: 'json', code: failure, title: '422 Unprocessable Content', numbers: true }"></pre>
            </section>
        </main>
    </div>
</template>

<footer>
    <script src="{{ resource('JS/wex.min.js') }}"></script>
    <script src="{{ resource('JS/wex-code.min.js') }}"></script>
    <script nonce="{{ csp_nonce() }}">
        WexCode.install(Wex);

        const docs = @json($docs);

        docs.resources.forEach((r) => r.endpoints.forEach((e, i) => { e.key = i; }));

        const params = [
            ['filters[поле][$оператор]=значение', 'Фильтр. Условия одного уровня объединяются через AND; связи: filters[author][name][$eq]=Ann'],
            ['filters[$or][0][…]&filters[$or][1][…]', 'Логическое ИЛИ; $and задаёт отдельную область для каждого элемента'],
            ['sort=title:asc,author.name:desc', 'Сортировка; по связям только BelongsTo. NULL ' + (docs.query.null_last ? 'в конце' : 'по правилам БД')],
            ['page[number]=1&page[size]=' + docs.query.pagination.per_page, 'Пагинация, максимум ' + docs.query.pagination.max_per_page + ' на страницу'],
            ['include=author,comments', 'Подгрузка связей из списка include ресурса'],
            ['fields=id,title', 'Ограничить поля ответа'],
            ['search=текст', 'Поиск по полям, помеченным как searchable'],
        ].map(([q, d]) => ({ q, d }));

        const query = `$model.get('posts')
    .filter('title', '$contains', 'Neo')
    .filter('author.name', 'Ann')
    .sort('author.name:asc')
    .paginate(${docs.query.pagination.per_page})
    .to(state.posts);`;

        const envelope = JSON.stringify({
            status: 'ok', code: 200, errors: [], data: [{ id: 1, title: 'Neo' }],
            paginator: {
                links: { first: '…?page[number]=1', last: '…?page[number]=3', prev: null, next: '…?page[number]=2' },
                meta: { rows: { from: 1, to: 15 }, pages: { current_page: 1, last_page: 3, per_page: 15, total: 40 } },
            },
        }, null, 2);

        const failure = JSON.stringify({
            status: 'error', code: 422,
            errors: [{ code: 'column_not_found', field: 'secret', message: 'Поле «secret» недоступно.' }],
        }, null, 2);

        // Выбор раздела: ресурс или «Запросы». Адрес раздела хранится в #hash.
        function show(state, name) {
            const resource = docs.resources.find((r) => r.name === name) ?? null;

            state.page = resource ? name : 'query';
            state.resource = resource;
            state.response = null;

            if (resource) {
                state.url = `${resource.url}?page[number]=1&page[size]=${resource.schema.pagination.per_page}`;
                state.example = `const page = await $model.get('${resource.name}').paginate(${resource.schema.pagination.per_page});\n`
                    + `page.data; page.paginator;`;
            }

            history.replaceState(null, '', '#' + state.page);
        }

        const app = $('#app').state(() => ({
            docs, params, query, envelope, failure,
            page: '', resource: null, url: '', example: '', response: null, loading: false,
        }));

        app.fn('open', (state, name) => show(state, name));

        app.fn('send', async (state) => {
            state.loading = true;

            try {
                const res = await fetch(state.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const text = await res.text();
                let body = text;

                try { body = JSON.stringify(JSON.parse(text), null, 2); } catch { /* не JSON */ }

                state.response = { title: `${res.status} ${res.statusText}`, body };
            } catch (e) {
                state.response = { title: 'Ошибка сети', body: String(e) };
            } finally {
                state.loading = false;
            }
        });

        app.on('$mount', (state) => show(state, location.hash.slice(1) || docs.resources[0]?.name || 'query'));
        app.mount();
    </script>
</footer>
</body>
</html>
