# Шаблонизатор Neo.

Шаблонизатор подразумевает в нашем контексте не только серверный рендеринг, 
но ещё и необязательный фронт-енд рендеринг. Поэтому полный пайплайн рендеринга
выглядит так.

## Шаг 1. Рендеринг на сервере.

```php 
Route::get('/', function() {
    return view('Home', [
        'myPhpVariable' => 5,
    ]);
});
```

## Шаг 2. Рендеринг на клиенте.

```html 
<template id="app">
    Значение переменной с сервера: {{ $myPhpVariable }},
    Значение переменной с клиента: @{{ myClientVariable }}.
</template>

<script >
    $('#app')
      .state({
          myClientVariable: 10,
      })
      .mount();
</script>
```

# Зачем?

С таким подходом мы получаем SSR + Реактивность одновременно. Важные 
статичные данные, такие, как например нужны для SEO мы передаём с 
сервера, можно прямо из кэша. Реактивные данные, которые должны 
регулярно обновляться "на лету", такие как например уведомления, 
мы обновляем реактивно на клиенте посылая запросы на сервер.

# Как это выглядит комплексно на большом примере?

Обьявляем модели:
```php 
class Comment extends Model {
    public int $id;

    #[BelongsTo(User::class)]
    public int $authorId;
    
    #[BelongsTo(Post::class)]
    public int $postId;
    
    #[BelongsTo(self)]
    public int $parentId;
    
    public string $text;
}

class Post extends Model {
    public int $id;

    #[BelongsTo(User::class)]
    public int $authorId;
    
    public string $title;
    
    public string $description;
}

class User extends Model {
    public int $id;

    public string $name;
}
```

Обьявляем ресурс:
```php 
class CommentsResourceAPI extends ResourceAPI {}
```

Обьявляем маршруты:
```php 
Route::resource(CommentsResourceAPI::class);
Route::get('/post/{slug}', function(string $slug) {
    $post = Post::where('slug', $slug)->first();

    return view('SinglePost', [
        'post' => $post,
    ]);
});
```

Объявляем шаблон:
```html 
<h1>{{ $post->title }}</h1>
<h1>{{ $post->description }}</h1>
<template id="comments">
    Комментарии в live-режиме:
    <div w-for="comment in comments" w-key="comment.id">
        <!-- Работаем с PIVOT-таблицей -->
        {{ comment.author.name }}
        {{ comment.comment.text }}
    </div>
</template>

<script >
    $('#comments')
      .state({
          comments: $model.list(),
      })
      .on('$mount', (state) => {
          
          // Каждые 3 секунды обновляем комментарии.
          $model.get('comments')
              .where('post_id', '{{ $post->id }}')
              .poll(3000)
              .to(state.comments);
          
      })      
      .mount();
</script>
```

## Итоги

- Получили SEO из коробки
- Получили Realtime динамику без боли