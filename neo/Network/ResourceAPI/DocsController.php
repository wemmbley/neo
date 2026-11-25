<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Neo\Network\Http\JsonResponse;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Security\Headers\CspNonce;

/**
 * Просмотрщик API: страница `/docs/api` и её данные `/docs/api/schema.json`.
 * Документация строится из самих ресурсов, поэтому не расходится с кодом.
 * Показывает только ресурсы, которые разрешает смотреть Policy `viewAny`.
 */
final class DocsController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ?ResourceAuthorizer $authorizer = null,
    ) {}

    public function data(Request $request): JsonResponse
    {
        $resources = [];

        foreach ($this->registry->all() as $entry) {
            $resource = $entry['resource'];

            if ($this->authorizer !== null && !$this->authorizer->can('viewAny', $resource->modelClass())) {
                continue;
            }

            $relations = [];
            foreach ($resource->relations() as $name => $definition) {
                $related = $definition->related !== null ? $this->registry->forModel($definition->related) : null;

                if ($related !== null) {
                    $relations[] = ['name' => $name, 'resource' => $related->resourceName(), 'many' => $definition->isMany()];
                }
            }

            $resources[] = [
                'name' => $resource->resourceName(),
                'url' => $request->root() . $entry['uri'],
                'endpoints' => array_map(static fn (array $r) => [
                    'method' => $r['method'],
                    'uri' => $r['uri'],
                    'action' => $r['action'],
                ], $entry['routes']),
                'schema' => $resource->schema(),
                'relations' => $relations,
            ];
        }

        return new JsonResponse([
            'resources' => $resources,
            'query' => [
                'operators' => ResourceAPI::OPERATORS,
                'null_last' => (bool) ResourceAPI::setting('null_last'),
                'relation_deep' => (int) ResourceAPI::setting('relation_deep'),
                'limits' => (array) ResourceAPI::setting('filters'),
                'pagination' => (array) ResourceAPI::setting('pagination'),
            ],
        ]);
    }

    public function page(Request $request): Response
    {
        try {
            $nonce = kernel(CspNonce::class)->get();
        } catch (\Throwable) {
            $nonce = '';
        }

        $attribute = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';
        $source = json_encode($request->url() . '/schema.json', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return new Response(
            str_replace(['{{nonce}}', '{{source}}'], [$attribute, (string) $source], self::TEMPLATE),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
        );
    }

    private const string TEMPLATE = <<<'HTML'
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Документация API</title>
<style{{nonce}}>
:root{--bg:#fff;--fg:#1b1f24;--muted:#667085;--line:#e4e7ec;--panel:#f6f7f9;--accent:#2457d6;--ok:#1a7f4b;--warn:#b54708}
@media (prefers-color-scheme:dark){:root{--bg:#14171c;--fg:#e6e8eb;--muted:#9aa4b2;--line:#2a2f37;--panel:#1b1f26;--accent:#7aa2ff;--ok:#4ade80;--warn:#fbbf24}}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--fg);display:flex;min-height:100vh}
nav{width:240px;flex:none;border-right:1px solid var(--line);padding:20px 12px;position:sticky;top:0;height:100vh;overflow:auto}
nav h1{font-size:15px;margin:0 8px 12px}nav a{display:block;padding:6px 8px;border-radius:6px;color:var(--fg);text-decoration:none}
nav a.on,nav a:hover{background:var(--panel);color:var(--accent)}
main{flex:1;min-width:0;max-width:980px;padding:28px 32px}h2{margin:0 0 4px}h3{margin:28px 0 8px;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.sub{color:var(--muted);margin:0 0 16px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:6px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;color:var(--muted);font-weight:600}code,.m{font:13px ui-monospace,monospace}code{background:var(--panel);padding:1px 5px;border-radius:4px}
.m{display:inline-block;min-width:56px;font-weight:700}.GET{color:var(--ok)}.POST,.PUT,.PATCH{color:var(--warn)}.DELETE{color:#d92d20}
.yes{color:var(--ok)}.no{color:var(--muted)}pre{background:var(--panel);padding:12px;border-radius:8px;overflow:auto;max-height:360px;margin:8px 0}
.try{display:flex;gap:8px}.try input{flex:1;padding:8px 10px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg);font:13px ui-monospace,monospace}
button{padding:8px 14px;border:0;border-radius:6px;background:var(--accent);color:#fff;font:inherit;cursor:pointer}
.chips span{display:inline-block;margin:2px 4px 2px 0;padding:1px 8px;border-radius:999px;background:var(--panel);font:12px ui-monospace,monospace}
@media (max-width:720px){body{display:block}nav{width:auto;height:auto;position:static;border:0;border-bottom:1px solid var(--line)}main{padding:16px}}
</style>
</head>
<body>
<nav><h1>API</h1><div id="nav"></div></nav>
<main id="main"><p class="sub">Загрузка…</p></main>
<script{{nonce}}>
(function(){
var el=function(t,c,x){var e=document.createElement(t);if(c)e.className=c;if(x!==undefined)e.textContent=x;return e};
var main=document.getElementById('main'),nav=document.getElementById('nav'),data=null;
fetch({{source}},{headers:{Accept:'application/json'},credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){
  data=d;d.resources.forEach(function(r){var a=el('a','',r.name);a.href='#'+r.name;nav.appendChild(a)});
  var q=el('a','','Запросы');q.href='#query';nav.appendChild(q);render();
}).catch(function(){main.textContent='Не удалось загрузить описание API.'});
window.addEventListener('hashchange',render);
function render(){
  if(!data)return;var h=location.hash.slice(1)||(data.resources[0]&&data.resources[0].name)||'query';
  Array.prototype.forEach.call(nav.children,function(a){a.className=a.getAttribute('href')==='#'+h?'on':''});
  main.textContent='';if(h==='query')return query();
  var r=data.resources.filter(function(x){return x.name===h})[0];if(!r){main.textContent='Ресурс не найден.';return}
  main.appendChild(el('h2','',r.name));main.appendChild(el('p','sub',r.url));
  main.appendChild(el('h3','','Маршруты'));
  var t=el('table');r.endpoints.forEach(function(e){var tr=el('tr');var m=el('td');m.appendChild(el('span','m '+e.method,e.method));tr.appendChild(m);tr.appendChild(el('td','',e.uri)).className='m';tr.appendChild(el('td','',e.action));t.appendChild(tr)});main.appendChild(t);
  main.appendChild(el('h3','','Поля'));
  var f=el('table'),hd=el('tr');['Поле','Тип','Фильтр','Сортировка','Поиск','Запись'].forEach(function(x){hd.appendChild(el('th','',x))});f.appendChild(hd);
  r.schema.fields.forEach(function(x){var tr=el('tr');var n=el('td');n.appendChild(el('code','',x.name+(x.primary?' (pk)':'')));tr.appendChild(n);tr.appendChild(el('td','',x.type+(x.nullable?'?':'')));
    [x.filterable,x.sortable,x.searchable,x.writable].forEach(function(v){tr.appendChild(el('td',v?'yes':'no',v?'да':'—'))});f.appendChild(tr)});main.appendChild(f);
  if(r.schema.include.length){main.appendChild(el('h3','','include'));var c=el('div','chips');r.schema.include.forEach(function(i){c.appendChild(el('span','',i))});main.appendChild(c)}
  if(r.schema.filters.length){main.appendChild(el('h3','','Кастомные фильтры'));var c2=el('div','chips');r.schema.filters.forEach(function(i){c2.appendChild(el('span','','filters['+i+']'))});main.appendChild(c2)}
  if(r.relations.length){main.appendChild(el('h3','','Связи'));var rt=el('table');r.relations.forEach(function(x){var tr=el('tr');tr.appendChild(el('td','',x.name)).className='m';tr.appendChild(el('td','',(x.many?'много → ':'одна → ')+x.resource));rt.appendChild(tr)});main.appendChild(rt)}
  main.appendChild(el('h3','','Попробовать'));
  var box=el('div','try'),inp=el('input');inp.value=r.url+'?page[number]=1&page[size]='+r.schema.pagination.per_page;var go=el('button','','GET');box.appendChild(inp);box.appendChild(go);main.appendChild(box);
  var out=el('pre','','');main.appendChild(out);
  go.onclick=function(){out.textContent='…';fetch(inp.value,{headers:{Accept:'application/json'},credentials:'same-origin'}).then(function(x){return x.text().then(function(b){try{b=JSON.stringify(JSON.parse(b),null,2)}catch(e){}out.textContent=x.status+'\n'+b})}).catch(function(e){out.textContent=String(e)})};
}
function query(){
  var q=data.query;main.appendChild(el('h2','','Параметры запроса'));
  var rows=[['filters[поле][$оператор]=значение','Фильтр. Условия одного уровня объединяются через AND; связи: filters[author][name][$eq]=Ann'],
   ['filters[$or][0][…]&filters[$or][1][…]','Логическое ИЛИ; $and задаёт отдельную область для каждого элемента'],
   ['sort=title:asc,author.name:desc','Сортировка; по связям только BelongsTo. NULL '+(q.null_last?'в конце':'по правилам БД')],
   ['page[number]=1&page[size]='+q.pagination.per_page,'Пагинация, максимум '+q.pagination.max_per_page+' на страницу'],
   ['include=author,comments','Подгрузка связей из списка include ресурса'],
   ['fields=id,title','Ограничить поля ответа'],['search=текст','Поиск по полям, помеченным как searchable']];
  var t=el('table');rows.forEach(function(r){var tr=el('tr');tr.appendChild(el('td','m',r[0]));tr.appendChild(el('td','',r[1]));t.appendChild(tr)});main.appendChild(t);
  main.appendChild(el('h3','','Операторы'));var c=el('div','chips');q.operators.forEach(function(o){c.appendChild(el('span','',o))});main.appendChild(c);
  main.appendChild(el('p','sub','Глубина связей: '+q.relation_deep+'. Условий в запросе: до '+q.limits.max_conditions+', элементов в $in: до '+q.limits.max_in_items+', длина значения: до '+q.limits.max_value_length+'.'));
}
})();
</script>
</body>
</html>
HTML;
}
