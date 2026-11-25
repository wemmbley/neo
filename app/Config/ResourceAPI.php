<?php

declare(strict_types=1);

#
# Настройки ResourceAPI по умолчанию. Публикуются в app/Config/ResourceAPI.php,
# ключ в config(): `resourceapi`. Значения, которых нет в вашем файле, берутся отсюда.
#
return [

    #
    # Сортировать значения NULL в конец списка (в обоих направлениях).
    #
    'null_last' => true,

    #
    # Максимальная глубина вложений в связи (фильтр, сортировка, include).
    #
    'relation_deep' => 3,

    'pagination' => [
        # Сколько записей отдаёт ресурс по умолчанию.
        'per_page' => 15,

        # Больше клиент запросить не может (PerPageLimitReachedResourceAPIException).
        'max_per_page' => 50,
    ],

    'filters' => [
        # Условий в одном запросе.
        'max_conditions' => 20,

        # Элементов в `$in` и `$notIn`.
        'max_in_items' => 100,

        # Длина значения фильтра.
        'max_value_length' => 255,
    ],

    'documentation' => [

        # Просмотрщик раскрывает карту всего API, поэтому в продакшене выключен.
        'enabled' => env('APP_ENV') !== 'production',

    ],

    #
    # Где искать модель по имени класса ресурса (PostResourceAPI => Post).
    # Если у ресурса не задан $model, перебираются по порядку, затем пространство имён самого ресурса.
    #
    'models_namespaces' => ['App\\Database\\Models\\'],

];
