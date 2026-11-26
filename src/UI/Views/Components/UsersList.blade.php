<template id="app">
    <h2 class="mt-10 font-semibold">Список пользователей зарегестрированных в Neo:</h2>
    <p w-if="users.error" class="text-red-400">Ошибка: @{{ users.error }}</p>
    <div class="my-6 max-w-[520px]" w-class="{ 'opacity-45': users.loading }">
        <div class="border-b border-purple-950 px-3.5 py-2.5 transition-opacity duration-200" w-for="user in users" w-key="user.id">
            @{{ user.email }}
        </div>
        <p w-if="!users.length && !users.loading" class="py-3 text-purple-400">
            Пользователей нет
        </p>
    </div>
    @include('Layouts.Paginator')
</template>

<script>
    $('#app').state(() => ({
            users: $model.list(),
        }))
        .method('go', (state, n) => state.users.go(n))
        .on('$mount', (state) => {
            $model.get('users').paginate(5).to(state.users);
        })
        .mount();
</script>