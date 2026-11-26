<div class="my-4 mb-1 flex flex-wrap items-center gap-1.5" w-if="users.pager.last > 1">
    <button w-on="click:go(users.pager.current - 1)"
            w-disabled="users.pager.current <= 1 || users.loading"
            class="h-9 min-w-9 cursor-pointer rounded-lg border
            border-purple-900 bg-transparent px-2.5 text-purple-300
            transition-colors hover:not-disabled:bg-purple-950
            disabled:cursor-default disabled:opacity-35">
        ←
    </button>
    <span w-for="p in users.pager.pages traceby p.key">
        <span class="px-1 text-purple-700" w-if="p.gap">
            …
        </span>
        <button w-if="!p.gap"
                w-class="{ 'bg-purple-500 text-black border-purple-500
                font-bold': p.active }"
                w-disabled="users.loading"
                w-on="click:go(p.n)"
                class="h-9 min-w-9 cursor-pointer rounded-lg border
                border-purple-900 bg-transparent px-2.5 text-purple-300
                transition-colors hover:not-disabled:bg-purple-950
                disabled:cursor-default disabled:opacity-35">
            @{{ p.n }}
        </button>
    </span>
    <button w-on="click:go(users.pager.current + 1)"
            w-disabled="users.pager.current >= users.pager.last || users.loading"
            class="h-9 min-w-9 cursor-pointer rounded-lg border
            border-purple-900 bg-transparent px-2.5 text-purple-300
            transition-colors hover:not-disabled:bg-purple-950
            disabled:cursor-default disabled:opacity-35">
        →
    </button>
</div>
<p class="text-xs text-purple-400/70 tabular-nums"
   w-if="users.pager.total">
    Показано
    <span class="font-medium text-purple-300">@{{ users.pager.from }}–@{{ users.pager.to }}</span>
    из
    <span class="font-medium text-purple-300">@{{ users.pager.total }}</span>
</p>