<?php

declare(strict_types=1);

use Neo\Information\Collection\Collection;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\ORM\Attributes\DB;
use Neo\Information\Persistence\ORM\Attributes\Hidden;
use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Attributes\Relations\BelongsTo;
use Neo\Information\Persistence\ORM\Attributes\Relations\BelongsToMany;
use Neo\Information\Persistence\ORM\Attributes\Relations\HasMany;
use Neo\Information\Persistence\ORM\Builder;
use Neo\Information\Persistence\ORM\Model;
use Neo\Network\Http\Testing\TestClient;
use Neo\Network\ResourceAPI\FilterResourceAPI;
use Neo\Network\ResourceAPI\ResourceAPI;
use Neo\Network\ResourceAPI\ResourceAPIServiceProvider;
use Neo\Network\ResourceAPI\ResourceAuthorizer;
use Neo\Network\ResourceAPI\Exceptions\ForbiddenResourceAPIException;

#[DB(table: 'ra_chefs', timestamps: false)]
#[HasMany(RaRestaurant::class, 'restaurants', 'chef_id')]
final class RaChef extends Model
{
    #[PrimaryKey]
    public int $id;

    public string $name;

    public ?string $country = null;
}

#[DB(table: 'ra_tags', timestamps: false)]
final class RaTag extends Model
{
    #[PrimaryKey]
    public int $id;

    public string $label;
}

#[DB(table: 'ra_restaurants', timestamps: false)]
#[BelongsTo(RaChef::class, 'chef', 'chef_id')]
#[BelongsToMany(RaTag::class, 'tags', 'ra_restaurant_tag', 'restaurant_id', 'tag_id')]
final class RaRestaurant extends Model
{
    #[PrimaryKey]
    public int $id;

    public string $name;

    public int $stars = 0;

    public string $cuisine = '';

    public ?int $chef_id = null;

    public ?string $note = null;

    public ?string $opened = null;

    #[Hidden]
    public ?string $secret = null;
}

final class RaChefResource extends ResourceAPI
{
    protected ?string $model = RaChef::class;
    protected ?string $name = 'chefs';
    protected array $include = ['restaurants'];
    protected array $actions = ['index', 'show'];
}

final class RaTagResource extends ResourceAPI
{
    protected ?string $model = RaTag::class;
    protected ?string $name = 'tags';
}

final class RaTagResourceAPI extends ResourceAPI {}

final class RaPopularFilter extends FilterResourceAPI
{
    public function apply(Builder $query, mixed $value): void
    {
        $query->where('stars', '>=', 4);
    }
}

final class RaRestaurantResource extends ResourceAPI
{
    protected ?string $model = RaRestaurant::class;
    protected ?string $name = 'restaurants';
    protected array $renamedFields = ['cuisine' => 'kitchen'];
    protected array $include = ['chef', 'tags'];
    protected array $searchFields = ['name', 'cuisine'];
    protected array $filters = [RaPopularFilter::class];
    protected array $actions = ['index', 'show', 'store', 'update', 'destroy'];
    protected array|string $rules = [
        'name' => 'required|string|max:50',
        'stars' => 'integer|min:0|max:5',
        'cuisine' => 'string',
        'secret' => 'string',
    ];
}

/** Окружение: sqlite в памяти, три ресурса, документация. */
function ra_client(?ResourceAuthorizer $authorizer = null): TestClient
{
    $db = new DatabaseManager([
        'default' => 'sqlite',
        'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]],
    ]);
    Model::setConnectionResolver($db);

    $c = $db->connection();
    foreach ([
        'create table ra_chefs (id integer primary key autoincrement, name varchar(100) not null, country varchar(50))',
        'create table ra_tags (id integer primary key autoincrement, label varchar(50) not null)',
        'create table ra_restaurants (id integer primary key autoincrement, name varchar(100) not null, stars integer not null default 0,'
            . ' cuisine varchar(50) not null default \'\', chef_id integer, note varchar(100), opened date, secret varchar(50))',
        'create table ra_restaurant_tag (restaurant_id integer, tag_id integer)',
    ] as $sql) {
        $c->statement($sql);
    }

    $chefs = [['Ann', 'FR'], ['Bob', 'IT'], ['Иван', 'RU']];
    foreach ($chefs as [$name, $country]) {
        $c->insert('insert into ra_chefs (name, country) values (?, ?)', [$name, $country]);
    }

    foreach (['vegan', 'cheap', 'fancy'] as $label) {
        $c->insert('insert into ra_tags (label) values (?)', [$label]);
    }

    $rows = [
        ['Alpha', 5, 'Italian', 1, null, '2020-01-10', 's1'],
        ['Beta', 3, 'French', 1, 'cozy', '2021-05-01', 's2'],
        ['Gamma', 4, 'Italian', 2, 'loud', '2022-07-15', 's3'],
        ['delta', 1, 'Thai', 2, null, null, 's4'],
        ['Epsilon', 2, 'Thai', null, 'x_y%z', '2019-03-03', 's5'],
    ];
    foreach ($rows as $row) {
        $c->insert('insert into ra_restaurants (name, stars, cuisine, chef_id, note, opened, secret) values (?, ?, ?, ?, ?, ?, ?)', $row);
    }

    foreach ([[1, 1], [1, 3], [2, 2], [3, 1]] as $pair) {
        $c->insert('insert into ra_restaurant_tag (restaurant_id, tag_id) values (?, ?)', $pair);
    }

    ResourceAPI::configure(['debug' => true]);

    $client = new TestClient();

    if ($authorizer !== null) {
        $client->container->singleton(ResourceAuthorizer::class, fn () => $authorizer);
    }

    ResourceAPIServiceProvider::wire($client->router, $client->container, $client->handler);

    $client->router->prefix('api/v1')->group(function () use ($client) {
        $client->router->resource(RaRestaurantResource::class);
        $client->router->resource(RaChefResource::class);
        $client->router->resource(ResourceAPI::make(RaTag::class)->name('tags')->actions(['index']));
    });
    $client->router->documentation('/docs/api');

    return $client;
}

function ra_get(TestClient $c, string $uri, array $query = []): array
{
    $response = $c->get($uri, $query, ['Accept' => 'application/json']);

    return ['status' => $response->status(), 'body' => json_decode($response->getContent(), true) ?? [], 'response' => $response];
}

function ra_names(array $result): array
{
    return array_column($result['body']['data'] ?? [], 'name');
}

function ra_code(array $result): ?string
{
    return $result['body']['errors'][0]['code'] ?? null;
}

test('Collection paginator uses the ResourceAPI structure', function () {
    $p = (new Collection(range(1, 25)))->paginate(10, 2, ['path' => 'http://x.test/items']);
    $array = $p->toArray();

    expect(array_keys($array))->toBe(['data', 'paginator'])
        ->and($array['data'])->toBe(range(11, 20))
        ->and($array['paginator']['links']['next'])->toBe('http://x.test/items?page[number]=3&page[size]=10')
        ->and($array['paginator']['links']['last'])->toBe('http://x.test/items?page[number]=3&page[size]=10')
        ->and($array['paginator']['links']['prev'])->toBe('http://x.test/items?page[number]=1&page[size]=10')
        ->and($array['paginator']['meta']['rows'])->toBe(['from' => 11, 'to' => 20])
        ->and($array['paginator']['meta']['pages'])->toBe(['current_page' => 2, 'last_page' => 3, 'per_page' => 10, 'total' => 25]);

    $simple = (new Collection(range(1, 25)))->simplePaginate(10, 3, ['path' => 'http://x.test/items'])->toArray();

    expect($simple['paginator']['links'])->not->toHaveKey('last')
        ->and($simple['paginator']['links']['next'])->toBeNull()
        ->and($simple['paginator']['meta']['pages'])->toBe(['current_page' => 3, 'per_page' => 10]);
});

test('ResourceAPI index returns the envelope, hidden fields stay hidden and fields are renamed', function () {
    $c = ra_client();
    $r = ra_get($c, '/api/v1/restaurants', ['page' => ['size' => 2]]);

    expect($r['status'])->toBe(200)
        ->and($r['body']['status'])->toBe('ok')
        ->and($r['body']['code'])->toBe(200)
        ->and($r['body']['errors'])->toBe([])
        ->and($r['body']['data'])->toHaveCount(2)
        ->and(array_keys($r['body']['data'][0]))->toBe(['id', 'name', 'stars', 'kitchen', 'chef_id', 'note', 'opened'])
        ->and($r['body']['paginator']['meta']['pages'])->toBe(['current_page' => 1, 'last_page' => 3, 'per_page' => 2, 'total' => 5])
        ->and($r['body']['paginator']['links']['next'])->toContain('page[number]=2&page[size]=2');
});

test('Filters: operators, case insensitivity and renamed fields', function () {
    $c = ra_client();
    $q = fn (array $filters) => ra_names(ra_get($c, '/api/v1/restaurants', ['filters' => $filters]));

    expect($q(['name' => ['$eq' => 'DELTA']]))->toBe(['delta'])
        ->and($q(['name' => ['$eqc' => 'DELTA']]))->toBe([])
        ->and($q(['name' => ['$eqc' => 'delta']]))->toBe(['delta'])
        ->and($q(['name' => ['$contains' => 'ET']]))->toBe(['Beta'])
        ->and($q(['name' => ['$containsc' => 'ET']]))->toBe([])
        ->and($q(['name' => ['$startsWith' => 'ga']]))->toBe(['Gamma'])
        ->and($q(['name' => ['$endsWith' => 'ON']]))->toBe(['Epsilon'])
        ->and($q(['name' => ['$notContains' => 'a']]))->toBe(['Epsilon'])
        ->and($q(['stars' => ['$in' => ['1', '5']]]))->toBe(['Alpha', 'delta'])
        ->and($q(['stars' => ['$notIn' => ['1', '5']]]))->toBe(['Beta', 'Gamma', 'Epsilon'])
        ->and($q(['stars' => ['$gte' => '4']]))->toBe(['Alpha', 'Gamma'])
        ->and($q(['stars' => ['$gt' => '2', '$lt' => '5']]))->toBe(['Beta', 'Gamma'])
        ->and($q(['stars' => ['$between' => ['2', '3']]]))->toBe(['Beta', 'Epsilon'])
        ->and($q(['note' => ['$null' => 'true']]))->toBe(['Alpha', 'delta'])
        ->and($q(['note' => ['$null' => 'false']]))->toBe(['Beta', 'Gamma', 'Epsilon'])
        ->and($q(['kitchen' => ['$eq' => 'thai']]))->toBe(['delta', 'Epsilon'])
        ->and($q(['opened' => ['$gte' => '2021-01-01']]))->toBe(['Beta', 'Gamma'])
        ->and($q(['note' => ['$contains' => '_']]))->toBe(['Epsilon'])
        ->and($q(['note' => ['$contains' => '%']]))->toBe(['Epsilon']);
});

test('Filters through relations use nested EXISTS and combine in one scope', function () {
    $c = ra_client();
    $q = fn (array $filters) => ra_names(ra_get($c, '/api/v1/restaurants', ['filters' => $filters]));

    expect($q(['chef' => ['name' => ['$eq' => 'ann']]]))->toBe(['Alpha', 'Beta'])
        ->and($q(['chef' => ['$exists' => 'false']]))->toBe(['Epsilon'])
        ->and($q(['chef' => ['$exists' => 'true']]))->toBe(['Alpha', 'Beta', 'Gamma', 'delta'])
        ->and($q(['tags' => ['label' => ['$eq' => 'vegan']]]))->toBe(['Alpha', 'Gamma'])
        ->and($q(['tags' => ['$exists' => 'false']]))->toBe(['delta', 'Epsilon'])
        # через две связи: повар, у которого есть ресторан с 5 звёздами
        ->and($q(['chef' => ['restaurants' => ['stars' => ['$eq' => '5']]]]))->toBe(['Alpha', 'Beta'])
        # условия одной связи в одном EXISTS: у одного повара нет ресторана одновременно на 5 и 1 звезду
        ->and($q(['chef' => ['restaurants' => ['stars' => ['$eq' => '5'], 'kitchen' => ['$eq' => 'Thai']]]]))->toBe([])
        # разные элементы $and: у каждого свой EXISTS, поэтому находит повара с обоими
        ->and($q(['$and' => [
            ['chef' => ['restaurants' => ['stars' => ['$eq' => '3']]]],
            ['chef' => ['restaurants' => ['kitchen' => ['$eq' => 'Italian']]]],
        ]]))->toBe(['Alpha', 'Beta']);

    $chefs = ra_get($c, '/api/v1/chefs', ['filters' => ['restaurants' => ['stars' => ['$gte' => '4']]]]);
    expect(ra_names($chefs))->toBe(['Ann', 'Bob']);
});

test('$or and $and combine with the conditions of the same level', function () {
    $c = ra_client();
    $q = fn (array $filters) => ra_names(ra_get($c, '/api/v1/restaurants', ['filters' => $filters]));

    expect($q(['$or' => [['stars' => ['$eq' => '5']], ['name' => ['$eq' => 'delta']]]]))->toBe(['Alpha', 'delta'])
        ->and($q([
            '$or' => [['stars' => ['$eq' => '5']], ['stars' => ['$eq' => '1']]],
            'kitchen' => ['$eq' => 'Thai'],
        ]))->toBe(['delta'])
        ->and($q(['$or' => [
            ['chef' => ['name' => ['$eq' => 'Bob']]],
            ['chef' => ['$exists' => 'false']],
        ]]))->toBe(['Gamma', 'delta', 'Epsilon']);
});

test('Sorting: default key order, direction, NULL last, BelongsTo and the to-many ban', function () {
    $c = ra_client();
    $s = fn (string $sort) => ra_names(ra_get($c, '/api/v1/restaurants', ['sort' => $sort]));

    expect(ra_names(ra_get($c, '/api/v1/restaurants')))->toBe(['Alpha', 'Beta', 'Gamma', 'delta', 'Epsilon'])
        ->and($s('stars:desc'))->toBe(['Alpha', 'Gamma', 'Beta', 'Epsilon', 'delta'])
        ->and($s('stars'))->toBe(['delta', 'Epsilon', 'Beta', 'Gamma', 'Alpha'])
        # NULL в конце в обоих направлениях
        ->and($s('note:asc'))->toBe(['Beta', 'Gamma', 'Epsilon', 'Alpha', 'delta'])
        ->and($s('note:desc'))->toBe(['Epsilon', 'Gamma', 'Beta', 'Alpha', 'delta'])
        ->and($s('chef.name:desc,stars:desc'))->toBe(['Gamma', 'delta', 'Alpha', 'Beta', 'Epsilon'])
        ->and($s('kitchen,name'))->toBe(['Beta', 'Alpha', 'Gamma', 'Epsilon', 'delta']);

    $many = ra_get($c, '/api/v1/chefs', ['sort' => 'restaurants.stars']);
    expect($many['status'])->toBe(422)->and(ra_code($many))->toBe('sort_not_allowed');

    $paged = ra_get($c, '/api/v1/restaurants', ['sort' => 'chef.name', 'page' => ['size' => 2, 'number' => 2]]);
    expect($paged['body']['paginator']['meta']['pages']['total'])->toBe(5)
        ->and($paged['body']['data'])->toHaveCount(2);
});

test('Search, custom filters, include and fields', function () {
    $c = ra_client();

    expect(ra_names(ra_get($c, '/api/v1/restaurants', ['search' => 'ital'])))->toBe(['Alpha', 'Gamma'])
        ->and(ra_names(ra_get($c, '/api/v1/restaurants', ['filters' => ['ra_popular' => '1']])))->toBe(['Alpha', 'Gamma']);

    $inc = ra_get($c, '/api/v1/restaurants', ['include' => 'chef,tags', 'fields' => 'id,name', 'filters' => ['name' => ['$eq' => 'Alpha']]]);
    $row = $inc['body']['data'][0];

    expect(array_keys($row))->toBe(['id', 'name', 'chef', 'tags'])
        ->and($row['chef']['name'])->toBe('Ann')
        ->and(array_column($row['tags'], 'label'))->toBe(['vegan', 'fancy']);

    $show = ra_get($c, '/api/v1/chefs/1', ['include' => 'restaurants']);
    expect($show['body']['data']['restaurants'])->toHaveCount(2)
        ->and($show['body']['data']['restaurants'][0])->not->toHaveKey('secret');
});

test('Invalid requests produce stable error codes', function () {
    $c = ra_client();
    $err = fn (array $q) => ra_code(ra_get($c, '/api/v1/restaurants', $q));

    expect($err(['filters' => ['secret' => ['$eq' => 'x']]]))->toBe('column_not_found')
        ->and($err(['filters' => ['nope' => ['$eq' => 'x']]]))->toBe('column_not_found')
        ->and($err(['filters' => ['cuisine' => ['$eq' => 'x']]]))->toBe('column_not_found')
        ->and($err(['sort' => 'secret']))->toBe('column_not_found')
        ->and($err(['fields' => 'secret']))->toBe('column_not_found')
        ->and($err(['filters' => ['name' => ['$regex' => 'x']]]))->toBe('operator_not_allowed')
        ->and($err(['filters' => ['name' => 'x']]))->toBe('incorrect_request_params')
        ->and($err(['filters' => ['stars' => ['$eq' => 'many']]]))->toBe('incorrect_request_params')
        ->and($err(['filters' => ['stars' => ['$contains' => '1']]]))->toBe('incorrect_request_params')
        ->and($err(['sort' => 'stars:up']))->toBe('incorrect_request_params')
        ->and($err(['page' => ['size' => 51]]))->toBe('per_page_limit_reached')
        ->and($err(['page' => ['number' => 0]]))->toBe('incorrect_request_params')
        ->and($err(['filters' => ['stars' => ['$in' => array_map('strval', range(1, 101))]]]))->toBe('filter_limit_exceeded')
        ->and($err(['filters' => ['name' => ['$eq' => str_repeat('a', 256)]]]))->toBe('filter_limit_exceeded')
        ->and($err(['include' => 'secret']))->toBe('relation_not_allowed')
        ->and($err(['filters' => ['nope' => ['name' => ['$eq' => 'x']]]]))->toBe('relation_not_allowed')
        ->and($err(['filters' => ['chef' => ['restaurants' => ['chef' => ['restaurants' => ['name' => ['$eq' => 'x']]]]]]]))->toBe('relation_depth_exceeded');

    $many = [];
    for ($i = 0; $i < 21; $i++) {
        $many[] = ['stars' => ['$eq' => '1']];
    }
    expect($err(['filters' => ['$or' => $many]]))->toBe('filter_limit_exceeded');

    ResourceAPI::configure(['debug' => false]);
    $hidden = ra_get($c, '/api/v1/restaurants', ['filters' => ['secret' => ['$eq' => 'x']]]);
    $unknown = ra_get($c, '/api/v1/restaurants', ['filters' => ['zzz' => ['$eq' => 'x']]]);
    expect($hidden['status'])->toBe(422)
        ->and($hidden['body']['errors'][0]['message'])->toBe($unknown['body']['errors'][0]['message']);
});

test('Operators can be restricted per resource', function () {
    $c = ra_client();
    $c->router->prefix('x')->group(function () use ($c) {
        $c->router->resource(ResourceAPI::make(RaTag::class)->name('limited')->operators(['eq', '$contains']));
    });

    $ok = ra_get($c, '/x/limited', ['filters' => ['label' => ['$eq' => 'vegan']]]);
    $bad = ra_get($c, '/x/limited', ['filters' => ['label' => ['$in' => ['vegan']]]]);

    expect($ok['status'])->toBe(200)->and(count($ok['body']['data']))->toBe(1)
        ->and(ra_code($bad))->toBe('operator_not_allowed');
});

test('Write actions: validation, payload whitelist, update, delete and Location', function () {
    $c = ra_client();

    $bad = $c->json('POST', '/api/v1/restaurants', ['stars' => 9]);
    $body = json_decode($bad->getContent(), true);
    expect($bad->status())->toBe(422)
        ->and(array_column($body['errors'], 'field'))->toContain('name', 'stars')
        ->and($body['errors'][0]['code'])->toBe('validation_failed');

    $notAllowed = $c->json('POST', '/api/v1/restaurants', ['name' => 'X', 'chef_id' => 1]);
    expect($notAllowed->status())->toBe(422)
        ->and(json_decode($notAllowed->getContent(), true)['errors'][0]['code'])->toBe('payload_field_not_allowed');

    # renamed: клиент пишет `kitchen`, оригинальное имя недоступно
    $created = $c->json('POST', '/api/v1/restaurants', ['name' => 'Zeta', 'stars' => 4, 'secret' => 's']);
    expect(json_decode($created->getContent(), true)['errors'][0]['code'] ?? null)->toBe('payload_field_not_allowed');

    $created = $c->json('POST', '/api/v1/restaurants', ['name' => 'Zeta', 'stars' => 4, 'kitchen' => 'Greek']);
    $data = json_decode($created->getContent(), true);
    expect($created->status())->toBe(201)
        ->and($created->headers()->get('Location'))->toEndWith('/api/v1/restaurants/' . $data['data']['id'])
        ->and($data['data']['kitchen'])->toBe('Greek');
});

test('Update and destroy', function () {
    $c = ra_client();

    $patch = $c->json('PATCH', '/api/v1/restaurants/1', ['stars' => 2]);
    $put = $c->json('PUT', '/api/v1/restaurants/1', ['stars' => 2]);
    $ok = $c->json('PATCH', '/api/v1/restaurants/1', ['name' => 'Alpha2']);

    expect($patch->status())->toBe(200)
        ->and(json_decode($patch->getContent(), true)['data']['stars'])->toBe(2)
        ->and($put->status())->toBe(422)
        ->and(json_decode($ok->getContent(), true)['data']['name'])->toBe('Alpha2');

    $gone = $c->json('DELETE', '/api/v1/restaurants/2');
    $missing = ra_get($c, '/api/v1/restaurants/2');

    expect($gone->status())->toBe(204)
        ->and($missing['status'])->toBe(404)
        ->and(ra_code($missing))->toBe('record_not_found');
});

test('Disabled actions, unknown paths under the API prefix and the schema route', function () {
    $c = ra_client();

    $denied = $c->json('POST', '/api/v1/chefs', ['name' => 'X']);
    $body = json_decode($denied->getContent(), true);
    expect($denied->status())->toBe(405)->and($body['errors'][0]['code'])->toBe('action_not_allowed');

    $unknown = ra_get($c, '/api/v1/nothing');
    expect($unknown['status'])->toBe(404)->and($unknown['body']['status'])->toBe('error')->and(ra_code($unknown))->toBe('not_found');

    $outside = $c->get('/elsewhere', [], ['Accept' => 'application/json']);
    expect(json_decode($outside->getContent(), true)['errors'] ?? null)->toBeNull();

    $schema = ra_get($c, '/api/v1/restaurants/schema');
    $fields = array_column($schema['body']['data']['fields'], null, 'name');

    expect($schema['status'])->toBe(200)
        ->and($schema['body']['data']['resource'])->toBe('restaurants')
        ->and(array_keys($fields))->toBe(['id', 'name', 'stars', 'kitchen', 'chef_id', 'note', 'opened'])
        ->and($fields)->not->toHaveKey('secret')
        ->and($fields['stars']['type'])->toBe('int')
        ->and($fields['name']['writable'])->toBeTrue()
        ->and($fields['id']['writable'])->toBeFalse()
        ->and($schema['body']['data']['actions'])->toBe(['index', 'show', 'store', 'update', 'destroy']);
});

test('Documentation page and data are served and the path is reserved', function () {
    $c = ra_client();

    $page = $c->get('/docs/api');
    $data = $c->get('/docs/api/schema.json');
    $json = json_decode($data->getContent(), true);

    expect($page->status())->toBe(200)
        ->and($page->getContent())->toContain('Документация API')
        ->and(array_column($json['resources'], 'name'))->toBe(['restaurants', 'chefs', 'tags'])
        ->and(array_column($json['resources'][0]['endpoints'], 'action'))->toContain('schema', 'index', 'update');

    $clash = ra_client();
    expect(fn () => $clash->router->resource(ResourceAPI::make(RaTag::class)->name('docs/api'))->register())->toThrow(LogicException::class);

    $other = ra_client();
    expect(fn () => $other->router->documentation('/api/v1/restaurants'))->toThrow(LogicException::class);
});

test('Authorizer: forbidden actions and relations the user may not see', function () {
    $deny = new class implements ResourceAuthorizer {
        public array $denied = ['viewAny:RaTag', 'delete:RaRestaurant'];

        private function key(string $ability, string|object $subject): string
        {
            return $ability . ':' . (new ReflectionClass($subject))->getShortName();
        }

        public function authorize(string $ability, string|object $subject): void
        {
            if (in_array($this->key($ability, $subject), $this->denied, true)) {
                throw new ForbiddenResourceAPIException();
            }
        }

        public function can(string $ability, string|object $subject): bool
        {
            return !in_array($this->key($ability, $subject), $this->denied, true);
        }
    };

    $c = ra_client($deny);

    $forbidden = $c->json('DELETE', '/api/v1/restaurants/1');
    expect($forbidden->status())->toBe(403)
        ->and(json_decode($forbidden->getContent(), true)['errors'][0]['code'])->toBe('forbidden');

    $viaFilter = ra_get($c, '/api/v1/restaurants', ['filters' => ['tags' => ['label' => ['$eq' => 'vegan']]]]);
    $viaInclude = ra_get($c, '/api/v1/restaurants', ['include' => 'tags']);
    $direct = ra_get($c, '/api/v1/tags');

    expect(ra_code($viaFilter))->toBe('relation_not_allowed')
        ->and(ra_code($viaInclude))->toBe('relation_not_allowed')
        ->and($direct['status'])->toBe(403)
        ->and(ra_get($c, '/api/v1/restaurants', ['include' => 'chef'])['status'])->toBe(200);

    $docs = json_decode($c->get('/docs/api/schema.json')->getContent(), true);
    expect(array_column($docs['resources'], 'name'))->not->toContain('tags');
});

test('Model::filter() applies the resource rules to any query', function () {
    $c = ra_client();

    $names = RaRestaurant::filter(['filters' => ['stars' => ['$gte' => '4']], 'sort' => 'name:desc'])->get()
        ->map(fn ($m) => $m->name)->all();

    expect($names)->toBe(['Gamma', 'Alpha']);
    expect(fn () => RaRestaurant::filter(['filters' => ['secret' => ['$eq' => 'x']]]))->toThrow(Neo\Network\ResourceAPI\Exceptions\ColumnNotFoundResourceAPIException::class);
});

test('Resources are immutable after registration', function () {
    $resource = ResourceAPI::make(RaTag::class)->name('frozen');
    $c = ra_client();
    $c->router->resource($resource);

    expect(fn () => $resource->fields(['id']))->toThrow(LogicException::class);
});

test('Route::resource(Class) needs no name: model and URL come from the class, name() is optional', function () {
    $c = ra_client();
    $c->router->prefix('auto')->group(function () use ($c) {
        $c->router->resource(RaTagResourceAPI::class);
        $c->router->resource(RaTagResourceAPI::class)->name('labels');
    });
    $c->router->resource('legacy', RaTagResourceAPI::class);

    expect(ra_get($c, '/auto/ra_tags')['status'])->toBe(200)
        ->and(ra_names(ra_get($c, '/auto/labels')))->toBe([])
        ->and(count(ra_get($c, '/auto/labels')['body']['data']))->toBe(3)
        ->and(ra_get($c, '/legacy')['status'])->toBe(200);

    $docs = json_decode($c->get('/docs/api/schema.json')->getContent(), true);
    expect(array_column($docs['resources'], 'name'))->toContain('ra_tags', 'labels', 'legacy');
});
