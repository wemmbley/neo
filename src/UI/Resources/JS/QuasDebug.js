// | -------------------------------------------------------
// | Q U A S   D E B U G - v1.0
// | -------------------------------------------------------
// |
// | Подключать ПОСЛЕ quas.js. Файл есть - дебаг включён, файла нет - дебага нет.
// | Прод: просто не подключайте этот файл.
// |
// | Необязательные настройки (задать ДО подключения этого файла):
// |   Quas.DebugConfig = {
// |       ios: 15,              // минимальная целевая версия iOS (15.4 = с учётом @layer, dvh, :has)
// |       ignore: ['hover-touch'], // id правил, которые нужно отключить
// |       maxSamples: 5,        // сколько элементов показывать в одной группе
// |   }
// |
// | Консоль после загрузки:
// |   Quas.debug.summary()   - сводка по правилам (console.table)
// |   Quas.debug.rescan()    - проверить страницу заново, показать всё ещё раз
// |   Quas.debug.ignore(id)  - выключить правило на лету
// |   Quas.debug.rules       - список id правил
// |
// | Важно: баги отрисовки WebKit (когда DOM и размеры верные, а пиксели не рисуются)
// | не видны ни в getComputedStyle, ни в getBoundingClientRect. Поэтому детектор
// | ловит ИЗВЕСТНЫЕ опасные сочетания. Неизвестные баги проходят мимо - дополняйте
// | правила, как только находите новый, и проверяйте на реальном iPhone.
(function () {
    'use strict';

    const Q = window.Quas;
    if (!Q) {
        console.error('[Quas debug] подключите quas.debug.js ПОСЛЕ quas.js');
        return;
    }
    if (Q.debug) return; // повторное подключение

    const cfg = Object.assign(
        { ios: 15, ignore: [], maxSamples: 5, flushDelay: 250 },
        Q.DebugConfig
    );
    cfg.ignore = [...cfg.ignore];

    // =========================================================================
    // УТИЛИТЫ
    // =========================================================================

    const memo = fn => {
        const m = new Map();
        return k => (m.has(k) ? m.get(k) : (m.set(k, fn(k)), m.get(k)));
    };
    const uniq = a => [...new Set(a)];
    const px = v => parseFloat(v) || 0;
    const escRe = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    // Та же логика разбора вариантов, что и в Quas: md:hover:[&>p]:mt-4
    const splitVariants = s => s.match(/(?:\[[^\]]*]|[^:])+/g) ?? [];
    const caniuse = q => 'https://caniuse.com/?search=' + encodeURIComponent(q);

    // requestIdleCallback нет в Safari (iOS) - делаем свой с бюджетом 8 мс
    const ric = window.requestIdleCallback
        ? f => window.requestIdleCallback(f, { timeout: 1000 })
        : f => setTimeout(() => {
            const t0 = performance.now();
            f({ timeRemaining: () => Math.max(0, 8 - (performance.now() - t0)) });
        }, 16);

    // =========================================================================
    // СРЕДА
    // =========================================================================

    const UA = navigator.userAgent;
    const isIOS = /iP(hone|ad|od)/.test(UA) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    function detectBrowser() {
        let m;
        const ios = UA.match(/OS (\d+)[_.](\d+)/);
        if ((m = UA.match(/(Firefox|FxiOS)\/([\d.]+)/))) return 'Firefox ' + m[2];
        if ((m = UA.match(/(Edg|OPR|CriOS|Chrome)\/([\d.]+)/)))
            return ({ Edg: 'Edge', OPR: 'Opera', CriOS: 'Chrome iOS', Chrome: 'Chrome' })[m[1]] + ' ' + m[2];
        if ((m = UA.match(/Version\/([\d.]+).*Safari/)))
            return 'Safari ' + m[1] + (isIOS && ios ? ` (iOS ${ios[1]}.${ios[2]})` : '');
        return 'неизвестный браузер';
    }
    const ENV = detectBrowser() + (isIOS ? ' · движок WebKit' : '');

    // =========================================================================
    // РЕПОРТЕР (стиль Wex: бейдж + groupCollapsed + дедупликация)
    // =========================================================================

    const COLORS = { error: '#e11d48', warn: '#d97706', info: '#2563eb', neutral: '#475569' };
    const LEVELS = { error: 'ошибка', warn: 'предупреждение', info: 'подсказка' };
    const badge = bg => `background:${bg};color:#fff;padding:1px 5px;border-radius:3px`;

    const reported = new Map(); // key -> record
    const pending = [];
    const stats = { error: 0, warn: 0, info: 0, checked: 0 };
    let flushTimer = 0;

    function domPath(el, depth = 4) {
        const parts = [];
        for (let n = el; n && n.nodeType === 1 && parts.length < depth && n !== document.body; n = n.parentElement) {
            let s = n.localName;
            if (n.id) s += '#' + n.id;
            else if (n.classList.length) s += '.' + [...n.classList].slice(0, 2).join('.');
            parts.unshift(s);
        }
        return parts.join(' › ') || 'body';
    }

    // rule: { id, level, title, browsers, hint, fix, link }
    // res:  { culprits[], details, related[[label, el]], title, fix, key }
    function report(rule, el, res = {}, sig = '') {
        if (cfg.ignore.includes(rule.id)) return null;
        const key = rule.id + '|' + sig + '|' + (res.key ?? '');
        let rec = reported.get(key);
        if (rec) {
            rec.count++;
            if (el && rec.samples.length < cfg.maxSamples && !rec.samples.includes(el)) rec.samples.push(el);
            return rec;
        }
        rec = { rule, res, key, count: 1, samples: el ? [el] : [] };
        reported.set(key, rec);
        stats[rule.level]++;
        pending.push(rec);
        if (!flushTimer) flushTimer = setTimeout(flush, cfg.flushDelay);
        return rec;
    }

    function flush() {
        flushTimer = 0;
        for (const rec of pending.splice(0)) print(rec);
    }

    function print(rec) {
        const { rule, res } = rec;
        const title = res.title ?? rule.title;
        console.groupCollapsed(
            `%cQuas%c ${title}${rec.count > 1 ? `  ×${rec.count}` : ''}`,
            badge(COLORS[rule.level]), ''
        );
        console.log('Правило:', rule.id, `(${LEVELS[rule.level]})`);
        if (rule.browsers) console.log('Затронутые браузеры:', rule.browsers);
        if (res.culprits?.length) console.log('Классы-виновники:', res.culprits.join(' '));
        if (res.details) console.log('Детали:', res.details);
        if (rec.samples[0]) console.log('Путь в DOM:', domPath(rec.samples[0]));
        for (const el of rec.samples) console.log('Элемент (можно кликнуть):', el);
        if (rec.count > rec.samples.length)
            console.log(`…и ещё ${rec.count - rec.samples.length} элементов с таким же набором классов`);
        for (const [label, el] of res.related ?? []) console.log(label + ':', el);
        if (rule.hint) console.log('Подсказка:', rule.hint);
        const fix = res.fix ?? rule.fix;
        if (fix) console.log('Как исправить:', fix);
        if (rule.link) console.log('Подробнее:', rule.link);
        console.groupEnd();
    }

    const seenInternal = new Set();
    function internalError(where, e) {
        const k = where + String(e?.message);
        if (seenInternal.has(k)) return;
        seenInternal.add(k);
        console.error('[Quas debug] внутренняя ошибка в', where, e);
    }

    // =========================================================================
    // ТАБЛИЦА ВОЗМОЖНОСТЕЙ (версии iOS Safari, с которых фича работает без префикса)
    // Проверьте и поправьте под себя. ios: null - только проверка в текущем браузере.
    // =========================================================================

    function unsupported(minIos, sup) {
        const why = [];
        if (minIos != null && cfg.ios < minIos) why.push(`цель iOS ${cfg.ios} < ${minIos}`);
        let ok = true;
        try { ok = sup ? !!sup() : true; } catch { ok = true; }
        if (!ok) why.push('этот браузер не поддерживает');
        return why.length ? why.join('; ') : null;
    }

    const FEATURES = [
        {
            id: 'dvh', ios: 15.4, search: 'viewport-unit-variants',
            title: 'Единицы dvh/svh/lvh не поддерживаются целевыми браузерами',
            browsers: 'iOS Safari < 15.4, Chrome < 108, Firefox < 101',
            sup: () => CSS.supports('height', '1dvh'),
            match: t => /^(min-|max-)?(h|w|size)-[dsl]v[hw]$/.test(t.util) || /\d(dvh|svh|lvh|dvw|svw|lvw)/.test(t.arb ?? ''),
            hint: 'Старый браузер игнорирует объявление целиком: у элемента не будет высоты.',
            fix: 'Fallback в обычном CSS: height:100vh; height:100dvh; (старый Safari проигнорирует вторую строку), либо поднимите цель до iOS 15.4.',
        },
        {
            id: 'aspect-ratio', ios: 15, search: 'aspect-ratio',
            title: 'aspect-ratio не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 15, Chrome < 88',
            sup: () => CSS.supports('aspect-ratio', '1 / 1'),
            match: t => /^aspect-/.test(t.util),
            hint: 'Без aspect-ratio блок схлопнётся по высоте, пока не загрузится содержимое.',
            fix: 'Атрибуты width/height у img или padding-top hack.',
        },
        {
            id: 'has', ios: 15.4, search: ':has',
            title: ':has() в варианте/классе не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 15.4, Chrome < 105, Firefox < 121',
            sup: () => CSS.supports('selector(:has(a))'),
            match: t => t.raw.includes(':has('),
            hint: 'Весь селектор с :has() отбрасывается, правило не применяется.',
            fix: 'Класс-состояние через JS (w-bind:class) вместо :has().',
        },
        {
            id: 'rel-color', ios: 16.4, search: 'relative color syntax', level: 'error',
            title: 'Градиент from-/via-* не отрисуется: Quas использует rgb(from …) (relative color syntax)',
            browsers: 'iOS Safari < 16.4, Chrome < 119, Firefox < 128',
            sup: () => CSS.supports('color', 'rgb(from red r g b / 0)'),
            match: t => /^(from|via)-(?!\[)(?!\d+(\.\d+)?%$)./.test(t.util),
            // Если конечный цвет задан явно (to-*), rgb(from …) не используется
            ok: a => a.tokens.some(t => /^to-(?!\d+(\.\d+)?%$)./.test(t.util)),
            hint: 'from-*/via-* кладут в --q-gradient-to значение rgb(from <цвет> r g b / 0). Без поддержки синтаксиса переменная невалидна, и градиент пропадает целиком.',
            fix: 'Всегда задавайте to-* явно (например to-red-500/0 для прозрачного конца): to-* не использует relative color. Или цель iOS 16.4+.',
        },
        {
            id: 'color-mix', ios: 16.2, search: 'color-mix',
            title: 'Цвет темы с прозрачностью (primary/50) использует color-mix(), не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 16.2, Chrome < 111, Firefox < 113',
            sup: () => CSS.supports('color', 'color-mix(in oklab, red 50%, transparent)'),
            match: t => /(^|-)(base-(100|200|300|content)|primary|secondary|accent|neutral|info|success|warning|error)(-content)?\/\d+$/.test(t.util),
            hint: 'Quas генерирует color-mix(in oklab, var(--color-*) N%, transparent) для цветов темы с /opacity.',
            fix: 'Используйте обычные цвета палитры с /opacity (red-500/50) или цель iOS 16.2+.',
        },
        {
            id: 'text-balance', ios: 17.5, search: 'text-wrap: balance',
            title: 'text-balance не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 17.5, Chrome < 114, Firefox < 121',
            sup: () => CSS.supports('text-wrap', 'balance'),
            match: t => t.util === 'text-balance',
            hint: 'Это прогрессивное улучшение: в старых браузерах просто обычный перенос строк.',
            fix: 'Можно оставить как есть, если вёрстка не ломается.',
        },
        {
            id: 'text-pretty', ios: 26, search: 'text-wrap: pretty',
            title: 'text-pretty не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 26, Chrome < 117, Firefox (нет)',
            sup: () => CSS.supports('text-wrap', 'pretty'),
            match: t => t.util === 'text-pretty',
            hint: 'Прогрессивное улучшение: в старых браузерах просто обычный перенос строк.',
            fix: 'Можно оставить как есть.',
        },
        {
            id: 'subgrid', ios: 16, search: 'css subgrid',
            title: 'subgrid не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 16, Chrome < 117, Firefox < 71',
            sup: () => CSS.supports('grid-template-columns', 'subgrid'),
            match: t => /^grid-(cols|rows)-subgrid$/.test(t.util),
            hint: 'Объявление отбрасывается, сетка потомков не наследует дорожки родителя.',
            fix: 'Явная сетка на потомках или цель iOS 16+.',
        },
        {
            id: 'overscroll', ios: 16, search: 'overscroll-behavior',
            title: 'overscroll-behavior не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 16, Chrome < 63',
            sup: () => CSS.supports('overscroll-behavior', 'contain'),
            match: t => /^overscroll(-[xy])?-(auto|contain|none)$/.test(t.util),
            hint: 'В iOS < 16 scroll chaining и «резиновый» скролл не отключить этим свойством.',
            fix: 'Блокировка скролла body через JS для модалок.',
        },
        {
            id: 'lh-unit', ios: 16.4, search: 'lh unit',
            title: 'Единица lh (min-h-lh) не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 16.4, Chrome < 109, Firefox < 120',
            sup: () => CSS.supports('min-height', '1lh'),
            match: t => t.util === 'min-h-lh',
            hint: 'Объявление отбрасывается.',
            fix: 'Задайте min-height в rem или em.',
        },
        {
            id: 'safe-align', ios: null, search: 'align safe',
            title: 'Ключевое слово safe в выравнивании не поддерживается этим браузером',
            browsers: 'проверяется только в текущем браузере',
            sup: () => CSS.supports('justify-content', 'safe center'),
            match: t => /-(center|end)-safe$/.test(t.util),
            hint: 'Объявление отбрасывается, выравнивание не применится.',
            fix: 'Уберите -safe или используйте обычный center/end.',
        },
        {
            id: 'scroll-smooth', ios: 15.4, search: 'scroll-behavior',
            title: 'scroll-behavior: smooth не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 15.4',
            sup: () => CSS.supports('scroll-behavior', 'smooth'),
            match: t => t.util === 'scroll-smooth',
            hint: 'Прогрессивное улучшение: прокрутка будет мгновенной.',
            fix: 'Можно оставить как есть.',
        },
        {
            id: 'accent-color', ios: 15.4, search: 'accent-color',
            title: 'accent-color не поддерживается целевыми браузерами',
            browsers: 'iOS Safari < 15.4',
            sup: () => CSS.supports('accent-color', 'red'),
            match: t => /^accent-(?!none$)./.test(t.util),
            hint: 'Чекбоксы/радио останутся системного цвета.',
            fix: 'Можно оставить как есть или кастомный чекбокс.',
        },
    ];

    // =========================================================================
    // АНАЛИЗ КЛАССОВ (кэш по строке className: 1500 одинаковых карточек = 1 разбор)
    // =========================================================================

    const RE = {
        clip: /^overflow(-[xy])?-(hidden|auto|scroll)$/,
        round: /^rounded(-(t|r|b|l|tl|tr|bl|br))?(-.+)?$/,
        roundZero: /^rounded(-(t|r|b|l|tl|tr|bl|br))?-(none|\[0(px|rem)?\])$/,
        transform: /^(translate|scale|rotate|skew)(-.+)?$|^transform$/,
        transition: /^transition(-(all|transform))?$|^transition-\[.*(all|transform|filter).*\]$/,
        filter: /^(blur|brightness|contrast|grayscale|hue-rotate|invert|saturate|sepia|drop-shadow)(-.+)?$/,
        backdrop: /^backdrop-(blur|brightness|contrast|grayscale|hue-rotate|invert|saturate|sepia)(-.+)?$/,
        willChange: /^will-change-(transform|contents|scroll-position)$|^will-change-\[.*(transform|filter).*\]$/,
        anim: /^animate-(?!none$)/,
        hoverV: /^(group-|peer-)?hover$/,
        hoverGuard: /^(can-hover|hoverable|hover-hover|hover-capable)$/,
        vh: /^(min-|max-)?h-(screen|\[(100vh|calc\(100vh.*)\])$/,
    };

    const analyze = memo(str => {
        const tokens = [];
        for (const raw of str.split(/\s+/)) {
            if (!raw) continue;
            const parts = splitVariants(raw);
            const last = parts.pop() ?? '';
            const util = last.replace(/^!/, '').replace(/^-/, '');
            const arb = (util.match(/\[(.+)]$/) || [])[1] ?? null;
            tokens.push({ raw, variants: parts, util, arb });
        }
        const pick = re => tokens.filter(t => re.test(t.util)).map(t => t.raw);
        const a = {
            tokens,
            clip: pick(RE.clip),
            round: tokens.filter(t => RE.round.test(t.util) && !RE.roundZero.test(t.util)).map(t => t.raw),
            transform: pick(RE.transform),
            transition: pick(RE.transition),
            filter: pick(RE.filter),
            backdrop: pick(RE.backdrop),
            willChange: pick(RE.willChange),
            anim: pick(RE.anim),
            vh: pick(RE.vh),
            isolate: tokens.some(t => t.util === 'isolate'),
            hoverMotion: tokens
                .filter(t => t.variants.some(v => RE.hoverV.test(v)) &&
                    !t.variants.some(v => RE.hoverGuard.test(v)) &&
                    RE.transform.test(t.util))
                .map(t => t.raw),
            features: {},
        };
        for (const f of FEATURES) {
            if (f.ok && f.ok(a)) continue;
            const m = tokens.filter(f.match).map(t => t.raw);
            if (m.length) a.features[f.id] = m;
        }
        return a;
    });

    // =========================================================================
    // ПОМОЩНИКИ ДЛЯ COMPUTED-ПРАВИЛ
    // =========================================================================

    const isClipVal = v => v === 'hidden' || v === 'auto' || v === 'scroll';
    const hasRadius = cs => [
        'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomLeftRadius', 'borderBottomRightRadius',
    ].some(k => px(cs[k]) > 0);
    const dur = s => String(s).split(',').some(x => parseFloat(x) > 0);

    function computedMotion(cs) {
        const out = [];
        if (dur(cs.transitionDuration) && /\b(all|transform|filter|backdrop-filter)\b/.test(cs.transitionProperty))
            out.push('css:transition');
        if (cs.transform && cs.transform !== 'none') out.push('css:transform');
        if (/transform|perspective/.test(cs.willChange)) out.push('css:will-change');
        if (cs.animationName !== 'none' && dur(cs.animationDuration)) out.push('css:animation');
        return out;
    }

    function computedFilter(cs) {
        const out = [];
        if (cs.filter && cs.filter !== 'none') out.push('css:filter');
        const bf = cs.backdropFilter || cs.webkitBackdropFilter;
        if (bf && bf !== 'none') out.push('css:backdrop-filter');
        return out;
    }

    // Что делает предка контейнером для position: fixed
    function containingBlockReason(s) {
        if (s.transform && s.transform !== 'none') return 'transform';
        if (s.perspective && s.perspective !== 'none') return 'perspective';
        if (s.filter && s.filter !== 'none') return 'filter';
        const bf = s.backdropFilter || s.webkitBackdropFilter;
        if (bf && bf !== 'none') return 'backdrop-filter';
        if (/transform|perspective|filter/.test(s.willChange)) return 'will-change: ' + s.willChange;
        if (/paint|layout|strict|content/.test(s.contain)) return 'contain: ' + s.contain;
        return null;
    }

    function clipRadiusInfo(c) {
        if (c._cr !== undefined) return c._cr;
        const cs = c.cs(), a = c.a;
        if (!(isClipVal(cs.overflowX) || isClipVal(cs.overflowY)) || !hasRadius(cs)) return (c._cr = null);
        const culprits = [...a.clip, ...a.round];
        if (!a.clip.length) culprits.push('css:overflow:' + (isClipVal(cs.overflowX) ? cs.overflowX : cs.overflowY));
        if (!a.round.length) culprits.push('css:border-radius');
        return (c._cr = {
            culprits,
            motion: uniq([...a.transform, ...a.transition, ...a.willChange, ...a.anim, ...computedMotion(cs)]),
            filter: uniq([...a.filter, ...a.backdrop, ...computedFilter(cs)]),
        });
    }

    const CHILD_SEL = '.transition,[class*="transition-all"],[class*="transition-transform"],' +
        '[class*="scale-"],[class*="translate-"],[class*="rotate-"],[class*="skew-"],' +
        '[class*="animate-"],[class*="will-change-"]';

    const RULE_BROWSERS_WEBKIT = 'iOS Safari (весь WebKit, в т.ч. Chrome/Firefox на iOS), Safari macOS. Chrome/Firefox на Android и десктопе не затронуты';

    // =========================================================================
    // ПРАВИЛА ЭЛЕМЕНТОВ: check(c) -> null | { culprits, details, related, key, title, fix }
    // c = { el, a (анализ классов), cs() (getComputedStyle, лениво) }
    // =========================================================================

    const R = (id, level, title, o) => ({ id, level, title, ...o });

    const RULES = [

        R('clip-radius-motion', 'error',
            'WebKit: overflow + скругление + transform/transition на одном элементе - содержимое может не отрисоваться', {
                browsers: RULE_BROWSERS_WEBKIT,
                hint: 'Safari строит для такого элемента отдельный композитный слой с маской скругления. Если содержимое появляется уже после вставки в DOM (w-src, w-for), слой остаётся в старом состоянии: DOM и размеры верные, но на экране рисуется только часть (текст появляется после скролла).',
                fix: '1) Убрать overflow-hidden и скруглить дочерний элемент (у img: rounded-t-[18px] / rounded-l-[18px]) - в golden-lux это полностью убрало баг.\n2) Если клиппинг обязателен - снять transition и hover:translate/scale с этого же элемента, анимировать внутренний слой.\n3) isolate и overflow-clip как обходные пути не проверены - только на реальном iPhone.',
                check(c) {
                    const i = clipRadiusInfo(c);
                    if (!i || !i.motion.length) return null;
                    return { culprits: uniq([...i.culprits, ...i.motion]) };
                },
            }),

        R('clip-radius-filter', 'warn',
            'WebKit: overflow + скругление + filter/backdrop-filter - мерцание и пропадание содержимого', {
                browsers: RULE_BROWSERS_WEBKIT,
                hint: 'backdrop-blur/blur на элементе с клиппингом по скруглению в Safari даёт мерцание, артефакты на углах и пропадающие слои.',
                fix: 'Вынести blur на отдельный абсолютный дочерний слой без overflow-hidden, скругление задать самому слою.',
                check(c) {
                    const i = clipRadiusInfo(c);
                    if (!i || i.motion.length || !i.filter.length) return null;
                    return { culprits: uniq([...i.culprits, ...i.filter]) };
                },
            }),

        R('clip-radius-child', 'warn',
            'WebKit: потомок с transform/transition внутри overflow + скругление', {
                browsers: RULE_BROWSERS_WEBKIT,
                hint: 'Масштабируемый или сдвигаемый потомок (например group-hover:scale-110 у картинки) в Safari может вылезать за скругление родителя или мигать во время анимации.',
                fix: 'isolate на контейнере (isolation: isolate) - обычное лечение, либо скруглить сам потомок. Если isolate уже стоит, правило молчит - проверяйте на устройстве.',
                check(c) {
                    const i = clipRadiusInfo(c);
                    if (!i || i.motion.length || i.filter.length) return null;
                    if (c.a.isolate || c.cs().isolation === 'isolate') return null;
                    const ch = c.el.querySelector(CHILD_SEL);
                    if (!ch) return null;
                    const ca = analyze(ch.getAttribute('class') ?? '');
                    const cul = uniq([...ca.transform, ...ca.transition, ...ca.willChange, ...ca.anim]);
                    if (!cul.length) return null;
                    return {
                        culprits: i.culprits,
                        details: 'у потомка: ' + cul.join(' '),
                        related: [['Потомок с движением', ch]],
                    };
                },
            }),

        R('vh-ios', 'warn', 'iOS: 100vh включает скрытую адресную строку (h-screen / min-h-screen)', {
            browsers: 'iOS Safari, Chrome на Android',
            hint: 'На iPhone 100vh равно высоте при скрытой адресной строке, поэтому блок выше видимой области: нижняя часть (кнопки, футер) уходит под панель Safari.',
            fix: 'h-dvh / min-h-dvh (iOS 15.4+, в Quas есть). Для iOS 15.0-15.3 нужен fallback в обычном CSS: height:100vh; height:100dvh;',
            check: c => (c.a.vh.length ? { culprits: c.a.vh } : null),
        }),

        R('fixed-in-transform', 'error',
            'position: fixed внутри предка с transform/filter - перестаёт быть fixed', {
                browsers: 'все браузеры (особенно заметно на iOS)',
                hint: 'transform, filter, backdrop-filter, perspective, will-change, contain у предка делают его контейнером для fixed: элемент ведёт себя как absolute и едет вместе с предком. Важно: translate-*/scale-*/rotate-* в Quas всегда дают transform, даже translate-x-0 и scale-100.',
                fix: 'Вынести fixed-элемент из этого предка (в корень/body) или убрать свойство у предка.',
                check(c) {
                    if (c.cs().position !== 'fixed') return null;
                    for (let a = c.el.parentElement; a; a = a.parentElement) {
                        const why = containingBlockReason(getComputedStyle(a));
                        if (why) {
                            const an = analyze(a.getAttribute('class') ?? '');
                            return {
                                culprits: uniq([...an.transform, ...an.filter, ...an.backdrop, ...an.willChange]),
                                details: `предок <${a.localName}> создаёт контейнер: ${why}`,
                                related: [['Предок', a]],
                                key: domPath(a, 2),
                            };
                        }
                    }
                    return null;
                },
            }),

        R('sticky-overflow', 'warn', 'position: sticky внутри предка с overflow - прилипание не работает', {
            browsers: 'все браузеры',
            hint: 'Ближайший предок с overflow hidden/auto/scroll становится областью прокрутки для sticky. Если он сам не скроллится, элемент не прилипнет к окну.',
            fix: 'overflow-clip на предке не создаёт скролл-контейнер (Safari 16+; на iOS 15 не поддерживается) или убрать overflow у предка.',
            check(c) {
                if (c.cs().position !== 'sticky') return null;
                const bad = v => v !== 'visible' && v !== 'clip';
                for (let a = c.el.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
                    const s = getComputedStyle(a);
                    if (bad(s.overflowX) || bad(s.overflowY)) {
                        return {
                            culprits: analyze(a.getAttribute('class') ?? '').clip,
                            details: `предок <${a.localName}> имеет overflow: ${s.overflowX} / ${s.overflowY}`,
                            related: [['Предок с overflow', a]],
                            key: domPath(a, 2),
                        };
                    }
                }
                return null;
            },
        }),

        R('sticky-offset', 'warn', 'position: sticky без top/bottom/left/right ничего не делает', {
            browsers: 'все браузеры',
            hint: 'Без смещения прилипать некуда: sticky ведёт себя как relative.',
            fix: 'Добавьте top-0 (или нужную сторону).',
            check(c) {
                const cs = c.cs();
                if (cs.position !== 'sticky') return null;
                if ([cs.top, cs.bottom, cs.left, cs.right].every(v => v === 'auto')) return { culprits: ['sticky'] };
                return null;
            },
        }),

        R('hover-touch', 'warn', 'hover: с transform на тач-устройствах залипает после касания', {
            browsers: 'iOS Safari, Chrome на Android (тач)',
            hint: 'После тапа :hover остаётся на элементе до следующего касания в другом месте: карточка остаётся приподнятой/увеличенной, плюс создаётся композитный слой (триггер багов отрисовки в WebKit).',
            fix: "Свой вариант в Quas (задать до подключения quas.js):\nQuas.cssExtensions = [{ prefixes: { 'can-hover': { type: 'wrapper', handler: r => `@media (hover: hover) {${r}}` } } }]\nИ писать: can-hover:hover:-translate-y-1",
            check: c => (c.a.hoverMotion.length ? { culprits: c.a.hoverMotion } : null),
        }),

        R('img-no-size', 'warn', 'img без размеров и aspect-ratio: сдвиги раскладки при загрузке', {
            browsers: 'все браузеры; в WebKit дополнительно провоцирует баги перерисовки',
            hint: 'Пока src не загружен (или выставляется позже через w-src), высота img равна нулю, потом резко меняется. Раскладка прыгает, а в Safari динамически меняющаяся высота внутри слоя с клиппингом - частая причина пропавшего содержимого.',
            fix: 'Задайте aspect-[4/3] (или нужное соотношение) / h-* на img или его обёртке, либо атрибуты width и height.',
            check(c) {
                const el = c.el;
                if (el.localName !== 'img') return null;
                if (el.getAttribute('width') || el.getAttribute('height')) return null;
                if (c.cs().aspectRatio !== 'auto') return null;
                if (c.a.tokens.some(t => /^(min-|max-)?(h|size)-(?!auto$)./.test(t.util) || /^aspect-/.test(t.util))) return null;
                return {
                    culprits: [],
                    details: el.getAttribute('src') ? 'src задан' : 'src пока не задан (w-src?)',
                };
            },
        }),

        R('input-zoom', 'warn', 'iOS: поле ввода со шрифтом меньше 16px - страница зумится при фокусе', {
            browsers: 'iOS Safari',
            hint: 'Safari на iPhone автоматически увеличивает страницу при фокусе на input/select/textarea, если font-size меньше 16px, и потом не возвращает масштаб.',
            fix: 'text-base на поле (или max-sm:text-base, чтобы на десктопе остался мелкий шрифт). Не отключайте зум через maximum-scale=1 - это ломает доступность.',
            check(c) {
                const n = c.el.localName;
                if (n !== 'input' && n !== 'select' && n !== 'textarea') return null;
                if (n === 'input' && /^(hidden|checkbox|radio|range|file|button|submit|reset|image|color)$/.test(c.el.type)) return null;
                const fs = px(c.cs().fontSize);
                if (fs >= 16 || fs === 0) return null;
                return {
                    culprits: c.a.tokens.filter(t => /^text-(xs|sm|\[.+\])$/.test(t.util)).map(t => t.raw),
                    details: `font-size: ${c.cs().fontSize}`,
                };
            },
        }),

        R('grid-hfull-rowspan', 'warn', 'h-full на элементе grid с row-span: расхождения в WebKit', {
            browsers: 'Safari/WebKit',
            hint: 'Процентная высота у grid-элемента, занимающего несколько строк, в WebKit считается иначе, чем в Chrome/Firefox: блок может быть короче или выше нужного.',
            fix: 'Уберите h-full: grid-элементы и так растягиваются (self-stretch). Для содержимого карточки используйте flex flex-col внутри.',
            check(c) {
                const hf = c.a.tokens.filter(t => t.util === 'h-full').map(t => t.raw);
                if (!hf.length) return null;
                const rs = c.a.tokens.filter(t => /^row-span-/.test(t.util)).map(t => t.raw);
                if (!rs.length && !/^span/.test(c.cs().gridRowEnd)) return null;
                return { culprits: [...hf, ...rs] };
            },
        }),

        R('fixed-bottom-safe', 'info', 'iOS: fixed-элемент у нижнего края может уйти под индикатор «Home»', {
            browsers: 'iOS Safari (PWA / viewport-fit=cover)',
            hint: 'В режиме PWA или при viewport-fit=cover страница рисуется под индикатором жестов (~34px), и элемент с bottom-0 оказывается частично закрыт.',
            fix: 'Добавьте pb-[env(safe-area-inset-bottom)] элементу и viewport-fit=cover в <meta name="viewport">.',
            check(c) {
                const cs = c.cs();
                if (cs.position !== 'fixed' || px(cs.bottom) !== 0 || px(cs.top) === 0) return null;
                if (/safe-area-inset/.test(c.el.getAttribute('class') ?? '')) return null;
                const vp = document.querySelector('meta[name="viewport"]');
                const standalone = window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone;
                if (!standalone && !/viewport-fit\s*=\s*cover/.test(vp?.content ?? '')) return null;
                return { culprits: ['fixed', 'bottom-0'] };
            },
        }),

        R('attr-popover', 'warn', 'Атрибут popover не поддерживается целевыми браузерами', {
            browsers: 'iOS Safari < 17, Chrome < 114, Firefox < 125',
            link: caniuse('popover'),
            hint: 'В браузере без поддержки popover элемент остаётся обычным блоком: меню не открывается по кнопке и не прячется.',
            fix: 'Фолбэк на JS (класс/hidden) для iOS < 17 или цель iOS 17+.',
            check(c) {
                if (!c.el.hasAttribute('popover')) return null;
                const why = unsupported(17, () => 'popover' in HTMLElement.prototype);
                return why ? { culprits: ['[popover]'], details: why } : null;
            },
        }),

        R('attr-dialog', 'warn', '<dialog> не поддерживается целевыми браузерами', {
            browsers: 'iOS Safari < 15.4',
            link: caniuse('dialog'),
            hint: 'В старом Safari <dialog> - неизвестный элемент, showModal() не существует.',
            fix: 'Полифилл или div-модалка.',
            check(c) {
                if (c.el.localName !== 'dialog') return null;
                const why = unsupported(15.4, () => typeof HTMLDialogElement !== 'undefined' && 'showModal' in HTMLDialogElement.prototype);
                return why ? { culprits: ['<dialog>'], details: why } : null;
            },
        }),

        R('attr-inert', 'warn', 'Атрибут inert не поддерживается целевыми браузерами', {
            browsers: 'iOS Safari < 15.5, Chrome < 102, Firefox < 112',
            link: caniuse('inert'),
            hint: 'Без поддержки inert содержимое остаётся кликабельным и фокусируемым.',
            fix: 'Дублируйте aria-hidden + tabindex=-1 или полифилл.',
            check(c) {
                if (!c.el.hasAttribute('inert')) return null;
                const why = unsupported(15.5, () => 'inert' in HTMLElement.prototype);
                return why ? { culprits: ['[inert]'], details: why } : null;
            },
        }),

        // Правила на CSS-возможности из FEATURES
        ...FEATURES.map(f => R('feature-' + f.id, f.level ?? 'warn', f.title, {
            browsers: f.browsers,
            hint: f.hint,
            fix: f.fix,
            link: caniuse(f.search),
            check(c) {
                const m = c.a.features[f.id];
                if (!m) return null;
                const why = unsupported(f.ios, f.sup);
                return why ? { culprits: m, details: why } : null;
            },
        })),
    ];

    const ELEMENT_RULES = RULES.filter(r => r.check);

    // =========================================================================
    // ГЛОБАЛЬНЫЕ ПРОВЕРКИ (один раз при старте)
    // =========================================================================

    const RULE_NO_VIEWPORT = R('no-viewport', 'error', 'Нет <meta name="viewport"> - iPhone рисует страницу в ширину 980px', {
        browsers: 'все мобильные браузеры',
        hint: 'Без viewport мобильный браузер считает ширину окна равной ~980px и уменьшает страницу: все брейкпоинты max-sm/md работают как на десктопе.',
        fix: '<meta name="viewport" content="width=device-width, initial-scale=1">',
    });
    const RULE_VIEWPORT_WIDTH = R('viewport-width', 'warn', 'В <meta name="viewport"> нет width=device-width', {
        browsers: 'все мобильные браузеры',
        hint: 'Без width=device-width брейкпоинты считаются от виртуальной ширины.',
        fix: '<meta name="viewport" content="width=device-width, initial-scale=1">',
    });
    const RULE_NO_LAYER = R('no-layer', 'warn', 'Preflight Quas внутри @layer base не применится: нет поддержки @layer', {
        browsers: 'iOS Safari < 15.4, Chrome < 99, Firefox < 97',
        link: caniuse('css cascade layers'),
        hint: 'Весь reset Quas (box-sizing, margin/padding: 0, border, шрифты форм) завёрнут в @layer base. Браузер без поддержки отбрасывает блок целиком, и вёрстка поедет.',
        fix: 'Поднимите цель до iOS 15.4 (Quas.DebugConfig = { ios: 15.4 }) либо продублируйте reset в обычном CSS без @layer.',
    });

    // Проверка того, что Quas вообще генерирует нужные префиксы.
    // Читается текст сгенерированного stylesheet: работает независимо от браузера.
    const EMIT = [
        {
            cls: 'select-none', need: '-webkit-user-select', ios: Infinity,
            title: 'select-* не работает в Safari: Quas не добавляет -webkit-user-select',
            browsers: 'iOS Safari и Safari macOS (все версии)',
            hint: 'Safari не понимает user-select без префикса: текст по-прежнему выделяется, на iOS вылезает меню по долгому тапу.',
            fix: "Задать до подключения quas.js:\nQuas.cssExtensions = [{ utilities: { select: { $prop: ['-webkit-user-select', 'user-select'] } } }]",
        },
        {
            cls: 'backdrop-blur-sm', need: '-webkit-backdrop-filter', ios: 18,
            title: 'backdrop-* не работает в Safari < 18: Quas не добавляет -webkit-backdrop-filter',
            browsers: 'iOS Safari < 18, Safari macOS < 18',
            hint: 'Размытие фона (backdrop-blur-*) вообще не рисуется: остаётся только полупрозрачный фон.',
            fix: "В quas.js, BASE_CSS.backdrop, перед строкой backdrop-filter добавить такую же с префиксом:\n-webkit-backdrop-filter: var(--q-backdrop-blur) var(--q-backdrop-brightness) var(--q-backdrop-contrast) var(--q-backdrop-grayscale) var(--q-backdrop-hue-rotate) var(--q-backdrop-invert) var(--q-backdrop-saturate) var(--q-backdrop-sepia)\nи в 'backdrop' > 'filter' > 'none' добавить -webkit-backdrop-filter: none",
        },
        {
            cls: 'appearance-none', need: '-webkit-appearance', ios: 15.4,
            title: 'appearance-none не работает в Safari < 15.4: нет -webkit-appearance',
            browsers: 'iOS Safari < 15.4',
            hint: 'Системный вид select/input/button не сбрасывается.',
            fix: "Задать до подключения quas.js:\nQuas.cssExtensions = [{ utilities: { 'appearance-none': '-webkit-appearance: none; appearance: none' } }]",
        },
        {
            cls: 'hyphens-auto', need: '-webkit-hyphens', ios: 17,
            title: 'hyphens-* не работает в Safari < 17: нет -webkit-hyphens',
            browsers: 'iOS Safari < 17',
            hint: 'Автоматические переносы слов не включатся.',
            fix: "Задать до подключения quas.js:\nQuas.cssExtensions = [{ utilities: { hyphens: { $prop: ['-webkit-hyphens', 'hyphens'] } } }]",
        },
    ];

    const rulesFor = (css, cls) => (css.match(
        new RegExp('[^{}]*\\.' + escRe(CSS.escape(cls)) + '(?![\\w-])[^{}]*\\{[^}]*\\}', 'g')
    ) || []).join('\n');

    function auditEmission() {
        if (typeof Q.processClasses !== 'function') return;
        Q.processClasses(new Set(EMIT.map(e => e.cls)));
        const css = document.getElementById('q-css-utilities')?.textContent ?? '';
        for (const e of EMIT) {
            if (!(cfg.ios < e.ios)) continue;
            if (rulesFor(css, e.cls).includes(e.need)) continue;
            report(R('emit-' + e.cls, 'warn', e.title, { browsers: e.browsers, hint: e.hint, fix: e.fix }), null, { key: e.cls });
        }
    }

    function runGlobal() {
        const vp = document.querySelector('meta[name="viewport"]');
        if (!vp) report(RULE_NO_VIEWPORT, null);
        else if (!/width\s*=\s*device-width/.test(vp.content)) report(RULE_VIEWPORT_WIDTH, null, { details: `content="${vp.content}"` });

        const noLayer = typeof CSSLayerBlockRule === 'undefined';
        if (noLayer || cfg.ios < 15.4)
            report(RULE_NO_LAYER, null, { details: noLayer ? 'в этом браузере @layer не поддерживается' : `цель iOS ${cfg.ios} < 15.4` });

        try { auditEmission(); } catch (e) { internalError('emission', e); }
    }

    // Подхватываем собственные предупреждения Quas (неизвестные классы и т.п.)
    const RULE_QUAS_WARN = R('quas-warning', 'warn', 'Quas: предупреждение', {
        hint: 'Quas не смог превратить токен в CSS: опечатка в классе или варианте, неподдерживаемое значение либо утилиты нет в UTILITY_CONFIG.',
    });
    const prevWarnings = Q.CSSWarnings;
    Q.CSSWarnings = (token, message) => {
        try { if (typeof prevWarnings === 'function') prevWarnings(token, message); } catch { /* пользовательский хук */ }
        report(RULE_QUAS_WARN, null, {
            key: String(token) + '|' + message,
            title: 'Quas: ' + String(message).replace(/^\[quas\]\s*/i, ''),
            culprits: token ? [String(token)] : [],
        });
        return false; // родной console.warn не дублируем
    };

    // =========================================================================
    // ОЧЕРЕДЬ ПРОВЕРКИ ЭЛЕМЕНТОВ
    // =========================================================================

    const SKIP = new Set(['SCRIPT', 'STYLE', 'LINK', 'META', 'HEAD', 'TITLE', 'NOSCRIPT', 'TEMPLATE', 'BR', 'CSS']);
    const queue = new Set();
    const sigMap = new Map(); // сигнатура -> записи, найденные при первой проверке
    let ready = false, scheduled = false, initialDone = false;

    // Сигнатура: сам элемент + два предка (контекст важен для computed-правил)
    function sigOf(el) {
        let s = '';
        let n = el;
        for (let i = 0; i < 3 && n && n.nodeType === 1; i++, n = n.parentElement)
            s += n.localName + '.' + (n.getAttribute('class') ?? '') + '<';
        if (el.localName === 'img') s += el.hasAttribute('src') ? 's' : 'n';
        if (el.localName === 'input') s += el.type;
        if (el.hasAttribute('popover')) s += 'P';
        if (el.hasAttribute('inert')) s += 'I';
        return s;
    }

    function audit(el) {
        stats.checked++;
        const sig = sigOf(el);
        const known = sigMap.get(sig);
        if (known) {
            // Такой же набор уже проверен: только считаем повторы
            for (const rec of known) {
                rec.count++;
                if (rec.samples.length < cfg.maxSamples) rec.samples.push(el);
            }
            return;
        }
        const recs = [];
        sigMap.set(sig, recs);
        let _cs;
        const c = { el, a: analyze(el.getAttribute('class') ?? ''), cs: () => (_cs ??= getComputedStyle(el)) };
        for (const rule of ELEMENT_RULES) {
            if (cfg.ignore.includes(rule.id)) continue;
            let res;
            try { res = rule.check(c); } catch (e) { internalError(rule.id, e); continue; }
            if (res) {
                const rec = report(rule, el, res, sig);
                if (rec) recs.push(rec);
            }
        }
    }

    function enqueue(el) {
        if (el instanceof HTMLElement && !SKIP.has(el.tagName)) queue.add(el);
    }

    function enqueueTree(node) {
        if (!node || node.nodeType !== 1) return;
        enqueue(node);
        for (const d of node.querySelectorAll('*')) enqueue(d);
    }

    function schedule() {
        if (scheduled || !ready || !queue.size) return;
        scheduled = true;
        ric(pump);
    }

    function pump(deadline) {
        scheduled = false;
        let n = 0;
        while (queue.size && (n++ < 1 || deadline.timeRemaining() > 1.5)) {
            const el = queue.values().next().value;
            queue.delete(el);
            if (!el.isConnected) continue;
            try { audit(el); } catch (e) { internalError('audit', e); }
        }
        if (queue.size) schedule();
        else if (!initialDone) {
            initialDone = true;
            setTimeout(() => { flush(); summaryLine(); }, cfg.flushDelay + 50);
        }
    }

    function summaryLine() {
        const total = stats.error + stats.warn + stats.info;
        console.info(
            `%cQuas%c проверено элементов: ${stats.checked}. Найдено (уникальных): ${stats.error} ошибок, ${stats.warn} предупреждений, ${stats.info} подсказок.` +
            (total ? ' Quas.debug.summary() - сводка по правилам.' : ' Известных проблем нет. Проверьте ещё на реальном iPhone.'),
            badge(COLORS.neutral), ''
        );
    }

    // Наблюдатель стартует сразу, обработка - когда Quas снял блокировку transition
    new MutationObserver(muts => {
        for (const m of muts) {
            if (m.type === 'childList') for (const n of m.addedNodes) enqueueTree(n);
            else enqueue(m.target);
        }
        schedule();
    }).observe(document.documentElement, {
        subtree: true, childList: true, attributes: true, attributeFilter: ['class'],
    });

    let tries = 0;
    function start() {
        // Пока висит q-css-no-transitions, computed-значения transition нулевые
        if (document.getElementById('q-css-no-transitions') && tries++ < 60) return setTimeout(start, 50);
        ready = true;
        runGlobal();
        enqueueTree(document.documentElement);
        schedule();
    }

    // =========================================================================
    // ПУБЛИЧНОЕ API
    // =========================================================================

    Q.debug = {
        version: '1.0',
        config: cfg,
        stats,
        rules: [...RULES.map(r => r.id), RULE_NO_VIEWPORT.id, RULE_VIEWPORT_WIDTH.id, RULE_NO_LAYER.id, ...EMIT.map(e => 'emit-' + e.cls)],
        ignore(id) { if (!cfg.ignore.includes(id)) cfg.ignore.push(id); },
        rescan(root = document.documentElement) {
            reported.clear();
            sigMap.clear();
            stats.error = stats.warn = stats.info = stats.checked = 0;
            initialDone = false;
            runGlobal();
            enqueueTree(root);
            schedule();
        },
        summary() {
            flush();
            const by = {};
            for (const rec of reported.values()) {
                const row = (by[rec.rule.id] ??= { Правило: rec.rule.id, Уровень: LEVELS[rec.rule.level], Шаблонов: 0, Элементов: 0 });
                row.Шаблонов++;
                row.Элементов += rec.count;
            }
            console.table(Object.values(by));
        },
    };

    console.info(
        `%cQuas debug%c включён. Цель: iOS ≥ ${cfg.ios}. Среда: ${ENV}.`,
        badge(COLORS.neutral), ''
    );

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();