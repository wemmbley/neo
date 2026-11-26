@include('Layouts.Header')

<body class="bg-[#1F0433] text-purple-500">
    <nav class="mt-5 flex items-center justify-between px-6 py-3 max-w-[80vw] m-auto border border-purple-900 rounded-xl">
        <img class="max-h-20" src="{{ resource('Images/Neo.png') }}" alt="logotype of neo">
        <ul class="flex items-center gap-8 text-[15px]">
            <li class="relative group">
                <button class="flex items-center gap-2 py-3 transition-colors cursor-pointer
                border border-purple-900 px-5 rounded-xl">
                    Документация
                    <span class="text-sm transition-transform duration-200 group-hover:rotate-180">
                        <i class="fas fa-sort-down mb-2"></i>
                    </span>
                </button>
                <ul class="invisible absolute left-0 top-full z-50 min-w-42
                translate-y-2 rounded-lg border border-purple-900/60
                bg-[#160226] p-2 opacity-0 shadow-xl shadow-black/30
                transition-all duration-200 group-hover:visible
                group-hover:translate-y-0 group-hover:opacity-100">
                    <li>
                        <a href="/docs/api" class="block rounded-md px-4 py-2 text-base transition-colors hover:bg-purple-950 hover:text-purple-300">
                            API
                        </a>
                    </li>
                    <li>
                        <a href="/docs" class="block rounded-md px-4 py-2 text-base transition-colors hover:bg-purple-950 hover:text-purple-300">
                            Документация Neo
                        </a>
                    </li>
                </ul>
            </li>
            <li class="relative group">
                <button class="flex items-center gap-2 py-3 transition-colors cursor-pointer
                border border-purple-900 px-5 rounded-xl">
                    Ссылки
                    <span class="text-sm transition-transform duration-200 group-hover:rotate-180">
                        <i class="fas fa-sort-down mb-2"></i>
                    </span>
                </button>
                <ul class="invisible absolute left-0 top-full z-50 min-w-28
                translate-y-2 rounded-lg border border-purple-900/60
                bg-[#160226] p-2 opacity-0 shadow-xl shadow-black/30
                transition-all duration-200 group-hover:visible
                group-hover:translate-y-0 group-hover:opacity-100">
                    <li>
                        <a href="https://github.com/wemmbley/neo" target="_blank" rel="noopener noreferrer" class="block rounded-md px-4 py-2 text-base transition-colors hover:bg-purple-950 hover:text-purple-300">
                            GitHub
                        </a>
                    </li>
                    <li>
                        <a href="https://t.me/" target="_blank" rel="noopener noreferrer" class="block rounded-md px-4 py-2 text-base transition-colors hover:bg-purple-950 hover:text-purple-300">
                            Telegram
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    </nav>

    <div class="max-w-[60vw] m-auto mt-10">
        <h1 class="text-2xl font-semibold">
            Добро пожаловать в Neo!
        </h1>
    </div>

</body>

@include('Layouts.Footer')