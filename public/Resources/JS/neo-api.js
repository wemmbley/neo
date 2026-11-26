/**
 * Neo API client — клиент для ResourceAPI.
 *
 * Один файл, три слоя:
 *
 *   1. Ядро (низкий уровень)   NeoAPI.Query, NeoAPI.Http, NeoAPI.ResourceError, NeoAPI.serialize
 *      Query — иммутабельное дерево параметров запроса; serialize превращает его в
 *      строку вида  filters[title][$contains]=Neo&sort=author.name:asc.
 *      Http — транспорт поверх fetch: конфиг, заголовки, токен, разбор конверта и ошибок.
 *
 *   2. Адаптер ресурсов        $model
 *      $model.config({ url: 'localhost/api/v1' });
 *      await $model.get('posts').filter('title', '$contains', 'Neo').sort('author.name:asc');
 *
 *   3. Именованные маршруты     $route
 *      Манифест (Route::manifest на сервере) запрашивается один раз; кеш — на ETag/max-age браузера.
 *      $route.get('posts.show', { id: 5 })  →  'http://localhost/api/v1/posts/5'
 *
 * Без зависимостей. Работает в браузере и в Node 18+ (fetch). В Node экспортируется как module.exports.
 */
(function (root, factory) {
    console.log('[Neo]: Neo API v1.2.0');

    const api = factory(root);

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }

    root.NeoAPI = api;
    root.$model = api.model;
    root.$route = api.route;
})(typeof globalThis !== 'undefined' ? globalThis : this, function (root) {
    'use strict';

    // ======================================================================
    // Ядро: ошибки
    // ======================================================================

    /** Ошибка ответа API: HTTP-статус, код и список ошибок из конверта `{status:"error", errors:[…]}`. */
    class ResourceError extends Error {
        constructor(status, errors = [], body = null) {
            super(errors[0]?.message || `HTTP ${status}`);

            this.name = 'ResourceError';
            this.status = status;
            this.errors = errors;
            this.body = body;
        }

        /** Код первой ошибки: `column_not_found`, `validation_failed`, … */
        get code() {
            return this.errors[0]?.code ?? null;
        }

        /** Сообщения по полю (удобно для форм): `error.field('email')`. */
        field(name) {
            return this.errors.filter((e) => e.field === name).map((e) => e.message);
        }
    }

    // ======================================================================
    // Ядро: сериализация параметров
    // ======================================================================

    const encode = (part) => encodeURIComponent(part).replace(/%24/g, '$').replace(/%2C/gi, ',').replace(/%3A/gi, ':');

    const scalar = (value) => {
        if (value instanceof Date) {
            const iso = value.toISOString();
            return iso.endsWith('T00:00:00.000Z') ? iso.slice(0, 10) : iso;
        }

        return typeof value === 'boolean' ? String(value) : String(value);
    };

    /** Вложенный объект/массив → пары `ключ[вложенный]=значение` (PHP-формат). */
    const flatten = (value, prefix, pairs = []) => {
        if (value === undefined || value === null) {
            return pairs;
        }

        if (Array.isArray(value)) {
            value.forEach((item, index) => {
                const isList = item === null || typeof item !== 'object' || item instanceof Date;
                flatten(item, `${prefix}[${isList ? '' : index}]`, pairs);
            });
        } else if (typeof value === 'object' && !(value instanceof Date)) {
            Object.entries(value).forEach(([key, item]) => flatten(item, `${prefix}[${key}]`, pairs));
        } else {
            pairs.push([prefix, scalar(value)]);
        }

        return pairs;
    };

    /** `{ filters: {...}, sort: 'id', page: {number: 2} }` → `filters[...]=…&sort=id&page[number]=2`. */
    const serialize = (params) =>
        Object.entries(params)
            .flatMap(([key, value]) => flatten(value, key))
            .map(([key, value]) => `${key.replace(/[^[\]]+/g, encode)}=${encode(value)}`)
            .join('&');

    // ======================================================================
    // Ядро: Query — иммутабельное дерево запроса
    // ======================================================================

    const OPERATORS = {
        '=': '$eq', '==': '$eq', '!=': '$ne', '<>': '$ne',
        '>': '$gt', '>=': '$gte', '<': '$lt', '<=': '$lte',
        in: '$in', 'not in': '$notIn', contains: '$contains', like: '$contains',
        'not contains': '$notContains', starts: '$startsWith', ends: '$endsWith',
        null: '$null', between: '$between', 'not between': '$notBetween', exists: '$exists',
    };

    const operator = (op) => (op.startsWith('$') ? op : OPERATORS[op.toLowerCase()] ?? `$${op}`);

    /** `author.name` + `{ $eq: 'Ann' }` → `{ author: { name: { $eq: 'Ann' } } }`. */
    const nest = (path, condition) =>
        path.split('.').reduceRight((inner, segment) => ({ [segment]: inner }), condition);

    /** Объединяет деревья условий одного уровня (связи с одним именем сливаются в одну область EXISTS). */
    const merge = (target, source) => {
        for (const [key, value] of Object.entries(source)) {
            const here = target[key];
            const bothPlain = here && typeof here === 'object' && !Array.isArray(here)
                && value && typeof value === 'object' && !Array.isArray(value);

            target[key] = bothPlain ? merge({ ...here }, value) : value;
        }

        return target;
    };

    class Query {
        #state;

        constructor(state = {}) {
            this.#state = { filters: {}, or: [], and: [], sort: [], include: [], fields: [], ...state };
        }

        #with(patch) {
            return new Query({ ...this.#state, ...patch });
        }

        /**
         * filter('title')                          — поле заполнено (`$null=false`)
         * filter('title', 'Neo')                   — равно (`$eq`)
         * filter('stars', '>=', 4)                 — оператор символом или `$gte`
         * filter('author.name', '$contains', 'a')  — поле связи
         * filter({ stars: { $gte: 4 } })           — готовое дерево условий
         */
        filter(field, ...rest) {
            let condition;

            if (typeof field === 'object' && field !== null) {
                return this.#with({ filters: merge({ ...this.#state.filters }, field) });
            }

            if (rest.length === 0) {
                condition = nest(field, { $null: false });
            } else if (rest.length === 1) {
                condition = nest(field, { $eq: rest[0] });
            } else {
                condition = nest(field, { [operator(rest[0])]: rest[1] });
            }

            return this.#with({ filters: merge({ ...this.#state.filters }, condition) });
        }

        /** Условие по связи: `has('tags', (q) => q.filter('label', 'vegan'))` или `has('tags')` ($exists). */
        has(relation, callback) {
            const inner = callback ? callback(new Query()).#state.filters : { $exists: true };
            return this.filter({ [relation]: inner });
        }

        /** Связи нет: `$exists=false`. */
        doesntHave(relation) {
            return this.filter({ [relation]: { $exists: false } });
        }

        /** `$or`: каждая ветка — функция `(q) => q.filter(...)` или готовое дерево условий. */
        or(...branches) {
            return this.#with({ or: [...this.#state.or, ...this.#branches(branches)] });
        }

        /** `$and`: отдельная область для каждой ветки (нужна для разных условий по одной связи). */
        and(...branches) {
            return this.#with({ and: [...this.#state.and, ...this.#branches(branches)] });
        }

        #branches(branches) {
            return branches.flat().map((branch) =>
                typeof branch === 'function' ? branch(new Query()).#tree() : branch,
            );
        }

        /** `sort('author.name:asc')`, `sort('-created_at')`, `sort('title', 'desc')`, `sort(['a', 'b:desc'])`. */
        sort(...args) {
            const [first, direction] = args;
            const items = Array.isArray(first) ? first : String(first).split(',');
            const sort = items.map((item) => {
                const text = item.trim();
                if (text.startsWith('-')) return `${text.slice(1)}:desc`;
                return direction && !text.includes(':') ? `${text}:${direction}` : text;
            });

            return this.#with({ sort: [...this.#state.sort, ...sort] });
        }

        page(number, size) {
            return this.#with({ page: { number, ...(size ? { size } : {}) } });
        }

        size(size) {
            return this.#with({ page: { ...(this.#state.page ?? {}), size } });
        }

        /** `paginate(5)` — по 5 записей, первая страница; `paginate(5, 3)` — третья. */
        paginate(size, number = 1) {
            return this.page(number, size);
        }

        include(...relations) {
            return this.#with({ include: [...this.#state.include, ...relations.flat()] });
        }

        fields(...names) {
            return this.#with({ fields: [...this.#state.fields, ...names.flat()] });
        }

        search(text) {
            return this.#with({ search: text });
        }

        /** Дерево фильтров с учётом `$or` / `$and`. */
        #tree() {
            const { filters, or, and } = this.#state;

            return {
                ...filters,
                ...(or.length ? { $or: or } : {}),
                ...(and.length ? { $and: and } : {}),
            };
        }

        /** Параметры запроса как объект: именно это уходит в serialize(). */
        toParams() {
            const { sort, include, fields, page, search } = this.#state;
            const filters = this.#tree();

            return {
                ...(Object.keys(filters).length ? { filters } : {}),
                ...(sort.length ? { sort: sort.join(',') } : {}),
                ...(page ? { page } : {}),
                ...(include.length ? { include: include.join(',') } : {}),
                ...(fields.length ? { fields: fields.join(',') } : {}),
                ...(search ? { search } : {}),
            };
        }

        toString() {
            return serialize(this.toParams());
        }
    }

    // ======================================================================
    // Ядро: запись ответа в состояние
    // ======================================================================

    const isEmpty = (value) => value == null
        || (Array.isArray(value) ? value.length === 0 : typeof value === 'object' && Object.keys(value).length === 0);

    /**
     * Стратегии записи (как у $wire): replace — заменить, push — дописать в конец (бесконечная
     * прокрутка), keep — не трогать уже заполненное, strict — ошибка, если там уже есть данные.
     */
    const STRATEGIES = {
        replace: (current, incoming) => incoming,
        push: (current, incoming) => (Array.isArray(current) && Array.isArray(incoming) ? [...current, ...incoming] : incoming),
        keep: (current, incoming) => (isEmpty(current) ? incoming : current),
        strict: (current, incoming, key) => {
            if (!isEmpty(current)) throw new Error(`NeoAPI: «${key ?? 'target'}» уже содержит данные (strategy: strict).`);
            return incoming;
        },
    };

    /**
     * Записывает данные в цель:
     *   write(state, 'users', data)  — state.users = …
     *   write(state.users, null, data) — массив/объект меняется на месте (ссылка и реактивность целы)
     */
    const write = (target, key, incoming, strategy = 'replace') => {
        const resolve = STRATEGIES[strategy] ?? STRATEGIES.replace;

        if (key !== null && key !== undefined) {
            target[key] = resolve(target[key], incoming, key);
            return;
        }

        const next = resolve(target, incoming);

        if (next === target) return;

        if (Array.isArray(target)) {
            target.splice(0, target.length, ...(Array.isArray(next) ? next : [next]));
        } else if (target && typeof target === 'object') {
            Object.keys(target).forEach((name) => delete target[name]);
            Object.assign(target, next);
        } else {
            throw new TypeError('NeoAPI: .to() ожидает массив, объект или пару (объект, ключ).');
        }
    };

    // ======================================================================
    // Состояние списка: users.loading / users.error / users.pager
    // ======================================================================

    const emptyPager = () => ({ current: 1, last: 1, total: 0, from: 0, to: 0, size: 0, pages: [] });

    /** Номера страниц для кнопок: первая, последняя, соседи текущей и разрывы `{ gap: true }` между ними. */
    const pagesOf = (current, last) => {
        const numbers = new Set([1, last, current - 1, current, current + 1].filter((n) => n >= 1 && n <= last));
        const list = [];
        let previous = 0;

        [...numbers].sort((a, b) => a - b).forEach((n) => {
            if (n - previous > 1) list.push({ key: `gap${n}`, gap: true });
            list.push({ key: `p${n}`, n, active: n === current });
            previous = n;
        });

        return list;
    };

    /** Пагинатор из ответа сервера в виде, удобном для шаблона. Работает и для simplePaginate (без total). */
    const pagerOf = (paginator) => {
        const { pages = {}, rows = {} } = paginator?.meta ?? {};
        const current = pages.current_page ?? 1;
        const last = pages.last_page ?? (paginator?.links?.next ? current + 1 : current);

        return {
            current,
            last,
            total: pages.total ?? 0,
            from: rows.from ?? 0,
            to: rows.to ?? 0,
            size: pages.per_page ?? 0,
            pages: pagesOf(current, last),
        };
    };

    // ======================================================================
    // Ядро: Http — транспорт
    // ======================================================================

    class Http {
        constructor(config = {}) {
            this.config = {
                url: '',
                headers: {},
                token: null,
                credentials: 'same-origin',
                fetch: null,
                manifest: null,
                ...config,
            };
        }

        configure(config) {
            Object.assign(this.config, config);
            return this;
        }

        /** `localhost/api/v1` → `http://localhost/api/v1`; `/api/v1` остаётся относительным адресом. */
        get base() {
            const url = this.config.url.replace(/\/+$/, '');
            const hasScheme = /^[a-z][a-z\d+.-]*:\/\//i.test(url);

            if (hasScheme || url.startsWith('/') || url === '') return url;

            return `${root.location?.protocol ?? 'http:'}//${url}`;
        }

        /**
         * Адрес запроса. Путь без начального «/» берётся относительно `url` из конфига;
         * путь с «/» или полный адрес — уже готов и не меняется (иначе `/api` дважды попадёт в адрес).
         */
        resolve(path, query = '') {
            const text = String(path);
            const ready = /^([a-z][a-z\d+.-]*:)?\/\//i.test(text) || text.startsWith('/');
            const url = ready ? text : `${this.base}/${text}`;

            return query ? `${url}${url.includes('?') ? '&' : '?'}${query}` : url;
        }

        /** Один HTTP-запрос. Возвращает тело ответа; на ошибку API бросает ResourceError. */
        async request(method, path, { query = '', body, headers = {} } = {}) {
            const { token, credentials } = this.config;
            const send = this.config.fetch ?? root.fetch?.bind(root);

            if (!send) throw new Error('NeoAPI: fetch недоступен, передайте config({ fetch }).');

            const response = await send(this.resolve(path, query), {
                method,
                credentials,
                headers: {
                    Accept: 'application/json',
                    ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
                    ...(token ? { Authorization: `Bearer ${token}` } : {}),
                    ...this.config.headers,
                    ...headers,
                },
                ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
            });

            if (response.status === 204) return null;

            const text = await response.text();
            let data = null;

            try { data = text ? JSON.parse(text) : null; } catch { /* не JSON */ }

            if (!response.ok) {
                throw new ResourceError(response.status, data?.errors ?? [{ code: 'http_error', field: null, message: `HTTP ${response.status}` }], data);
            }

            return data;
        }
    }

    // ======================================================================
    // Именованные маршруты: $route
    // ======================================================================

    class Router {
        #http;
        #routes = null;
        #loading = null;
        #root = '';

        constructor(http) {
            this.#http = http;
        }

        /** Загружает манифест (один раз). Повторные вызовы возвращают тот же промис; `force` — перечитать. */
        load(url = this.#http.config.manifest, force = false) {
            if (!url) throw new Error('NeoAPI: укажите адрес манифеста — $model.config({ manifest: "/api/v1/routes.json" }).');

            if (!this.#loading || force) {
                this.#loading = this.#http.request('GET', url).then((data) => {
                    this.#routes = data.routes;
                    this.#root = data.root ?? '';
                    return this;
                });

                this.#loading.catch(() => { this.#loading = null; });
            }

            return this.#loading;
        }

        /** Манифест нужен и задан — дождаться загрузки. */
        async ready() {
            if (this.#routes === null && this.#http.config.manifest) await this.load();
            return this.#routes !== null;
        }

        has(name) {
            return Boolean(this.#routes?.[this.#normalize(name)]);
        }

        all() {
            return { ...(this.#routes ?? {}) };
        }

        /**
         * Адрес именованного маршрута. `posts` = `posts.index`; параметры подставляются в `{id}`,
         * лишние уходят в строку запроса.
         */
        get(name, params = {}) {
            const route = this.#routes?.[this.#normalize(name)];

            if (!route) {
                throw new Error(`NeoAPI: маршрут «${name}» не найден${this.#routes ? '' : ' (манифест не загружен: await $route.load())'}.`);
            }

            const rest = { ...params };
            const uri = route.uri.replace(/\{(\w+)\??\}/g, (_, key) => {
                const value = rest[key];
                delete rest[key];
                return value === undefined ? '' : encodeURIComponent(value);
            });

            const query = serialize(rest);
            return `${this.#root}${uri.replace(/\/+$/, '')}${query ? `?${query}` : ''}`;
        }

        /** HTTP-методы маршрута: `['PUT', 'PATCH']`. */
        methods(name) {
            return this.#routes?.[this.#normalize(name)]?.methods ?? [];
        }

        #normalize(name) {
            return this.#routes && !this.#routes[name] && this.#routes[`${name}.index`] ? `${name}.index` : name;
        }
    }

    // ======================================================================
    // Адаптер ресурсов: $model
    // ======================================================================

    /**
     * Цепочка запроса к ресурсу. Все методы Query доступны напрямую; сама цепочка — thenable,
     * поэтому `await $model.get('posts').filter(...)` выполняет GET списка.
     */
    class Resource {
        #adapter;
        #name;
        #query;

        constructor(adapter, name, query = new Query()) {
            this.#adapter = adapter;
            this.#name = name;
            this.#query = query;

            // Методы Query возвращают новую цепочку, не меняя текущую.
            for (const method of ['filter', 'has', 'doesntHave', 'or', 'and', 'sort', 'page', 'size', 'paginate', 'include', 'fields', 'search']) {
                this[method] = (...args) => new Resource(adapter, name, query[method](...args));
            }

            this.where = this.filter;
        }

        get query() {
            return this.#query;
        }

        /** Адрес запроса вместе со строкой параметров (удобно для отладки). */
        async url() {
            return this.#adapter.url(this.#name, this.#query.toString());
        }

        toString() {
            return this.#query.toString();
        }

        /** Список: `{ status, code, errors, data, paginator, items, next(), prev() }`. */
        async get() {
            const url = await this.#adapter.url(this.#name);
            return this.#adapter.page(await this.#adapter.http.request('GET', url, { query: this.#query.toString() }));
        }

        /** Все страницы подряд (асинхронный итератор): `for await (const post of $model.get('posts').each())`. */
        async *each() {
            let page = await this.get();

            while (page) {
                yield* page.data;
                page = page.next ? await page.next() : null;
            }
        }

        /** Первая запись по текущим фильтрам. */
        async first() {
            return (await this.size(1).get()).data[0] ?? null;
        }

        async find(id) {
            const { include, fields } = this.#query.toParams();
            const query = serialize({ ...(include ? { include } : {}), ...(fields ? { fields } : {}) });
            const body = await this.#adapter.http.request('GET', await this.#adapter.url(this.#name, '', id), { query });

            return body.data;
        }

        async create(data) {
            return (await this.#adapter.http.request('POST', await this.#adapter.url(this.#name), { body: data })).data;
        }

        /** PATCH по умолчанию; `{ full: true }` — PUT. */
        async update(id, data, { full = false } = {}) {
            const url = await this.#adapter.url(this.#name, '', id);
            return (await this.#adapter.http.request(full ? 'PUT' : 'PATCH', url, { body: data })).data;
        }

        async destroy(id) {
            await this.#adapter.http.request('DELETE', await this.#adapter.url(this.#name, '', id));
            return true;
        }

        /** Описание полей и возможностей ресурса. */
        async schema() {
            return (await this.#adapter.http.request('GET', await this.#adapter.url(this.#name, '', 'schema'))).data;
        }

        /**
         * Запрос сразу пишет результат в состояние, как `$wire.get(url).to(state)`:
         *
         *   $model.get('users').paginate(5).to(state.users)          // массив меняется на месте
         *   $model.get('users').to(state, 'users')                   // state.users = …
         *   $model.get('users').to(state.users).append()             // дописать (подгрузка)
         *   $model.get('users').to(state, 'users').meta(state, 'pager').done(fn).fail(fn)
         */
        to(target, key = null) {
            return new Delivery(this, target, key);
        }

        then(resolve, reject) {
            return this.get().then(resolve, reject);
        }
    }

    /**
     * Запущенный запрос с целью записи: done / fail / always / before, смена стратегии, запись пагинатора.
     *
     * Если цель — массив, он становится «состоянием списка»: на нём живут `loading`, `error`,
     * `pager` и методы `go(n)`, `next()`, `prev()`. Начальный вид даёт `$model.list()`.
     */
    class Delivery {
        #resource;
        #target;
        #key;
        #strategy = 'replace';
        #meta = null;
        #handlers = { done: [], fail: [], always: [] };
        #promise;

        constructor(resource, target, key) {
            this.#resource = resource;
            this.#target = target;
            this.#key = key;

            const list = this.#list;

            if (list) {
                list.loading = true;
                list.error = '';
                list.go = (n) => this.#go(n);
                list.next = () => this.#go(list.pager.current + 1);
                list.prev = () => this.#go(list.pager.current - 1);
            }

            // Запрос стартует сразу, а обработчики цепочки подключаются до прихода ответа.
            this.#promise = Promise.resolve()
                .then(() => resource.get())
                .then((page) => {
                    write(this.#target, this.#key, page.data, this.#strategy);

                    if (list && page.paginator) list.pager = pagerOf(page.paginator);
                    if (this.#meta) write(this.#meta.target, this.#meta.key, page.paginator ?? null);

                    this.#handlers.done.forEach((fn) => fn(page));
                    return page;
                })
                .catch((error) => {
                    if (list) list.error = error.message;
                    if (!this.#handlers.fail.length) console.error('[NeoAPI]', error);

                    this.#handlers.fail.forEach((fn) => fn(error));
                    return null;
                })
                .finally(() => {
                    if (list) list.loading = false;
                    this.#handlers.always.forEach((fn) => fn());
                });
        }

        /** Массив-цель (только при записи «на месте»). */
        get #list() {
            return this.#key == null && Array.isArray(this.#target) ? this.#target : null;
        }

        /** Загрузить страницу n тем же запросом в ту же цель (не выходя за границы и не дублируя текущую). */
        #go(n) {
            const { pager, loading } = this.#list;

            if (loading || n < 1 || n > pager.last || n === pager.current) return this;

            return new Delivery(this.#resource.page(n, pager.size || undefined), this.#target, this.#key)
                .strategy(this.#strategy);
        }

        /** Стратегия записи: replace (по умолчанию), push, keep, strict. */
        strategy(name) {
            this.#strategy = name;
            return this;
        }

        /** Дописывать к уже имеющимся данным (`push`). */
        append() {
            return this.strategy('push');
        }

        /** Кроме списка записать и пагинатор как есть (`links`, `meta`): `.meta(state, 'pager')`. */
        meta(target, key = null) {
            this.#meta = { target, key };
            return this;
        }

        before(fn) {
            fn();
            return this;
        }

        done(fn) {
            this.#handlers.done.push(fn);
            return this;
        }

        fail(fn) {
            this.#handlers.fail.push(fn);
            return this;
        }

        always(fn) {
            this.#handlers.always.push(fn);
            return this;
        }

        /** Delivery можно `await`: вернёт страницу или null при ошибке. */
        then(resolve, reject) {
            return this.#promise.then(resolve, reject);
        }
    }

    class Adapter {
        constructor(http, router) {
            this.http = http;
            this.router = router;
        }

        /** `$model.config({ url, token, headers, manifest, fetch, credentials })` */
        config(options) {
            this.http.configure(options);
            return this;
        }

        /**
         * Пустое состояние списка для шаблона: массив с `loading`, `error` и `pager`.
         *   .state(() => ({ users: $model.list() }))
         */
        list() {
            return Object.assign([], { loading: false, error: '', pager: emptyPager() });
        }

        /** Кнопки страниц: `$model.pages(5, 20)` → 1 … 4 5 6 … 20. */
        pages(current, last) {
            return pagesOf(current, last);
        }

        /** Начало цепочки для ресурса: `$model.get('posts')`. */
        get(name) {
            return new Resource(this, name);
        }

        /** Низкоуровневый доступ к транспорту. */
        get raw() {
            return this.http;
        }

        /** Адрес ресурса: из манифеста (если задан), иначе `{url}/{name}`. */
        async url(name, query = '', id = null) {
            let url;

            if (await this.router.ready() && this.router.has(`${name}.${id === null ? 'index' : 'show'}`)) {
                url = this.router.get(`${name}.${id === null ? 'index' : 'show'}`, id === null ? {} : { id });
            } else {
                url = this.http.resolve(id === null ? name : `${name}/${encodeURIComponent(id)}`);
            }

            return query ? `${url}${url.includes('?') ? '&' : '?'}${query}` : url;
        }

        /** Страница списка: тело ответа + `items`, `next()` и `prev()` (null, если страницы нет). */
        page(body) {
            const links = body?.paginator?.links ?? {};
            const follow = (link) => (link ? () => this.http.request('GET', link).then((data) => this.page(data)) : null);

            return { ...body, items: body?.data ?? [], next: follow(links.next), prev: follow(links.prev) };
        }
    }

    const http = new Http();
    const route = new Router(http);
    const model = new Adapter(http, route);

    return { Query, Http, Router, ResourceError, serialize, write, pagesOf, OPERATORS, model, route, http };
});