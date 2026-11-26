@include('Layouts.Header')

<body class="isolate min-h-screen bg-[#1a0633] bg-[linear-gradient(180deg,#14052b_0%,#2b0b4f_40%,#6a1b7e_72%,#ff5fa8_100%)] bg-fixed text-fuchsia-50 antialiased selection:bg-pink-500 selection:text-white">

<!-- Vaporwave фон: закат, полосатое солнце и неоновая сетка-пол -->
<div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
    <!-- Солнце: обёртка держит свечение, внутренний блок — полосатую маску (иначе маска обрезает shadow) -->
    <div class="absolute bottom-[18vh] left-1/2 h-[380px] w-[380px] -translate-x-1/2 rounded-full opacity-80
                shadow-[0_0_120px_30px_rgba(255,95,168,0.35)]">
        <div class="h-full w-full rounded-full
                    bg-gradient-to-b from-yellow-300 via-orange-400 to-pink-500
                    [mask-image:repeating-linear-gradient(to_bottom,#000_0,#000_16px,transparent_16px,transparent_20px)]"></div>
    </div>

    <!-- Сетка-пол -->
    <div class="absolute inset-x-0 bottom-0 h-[32vh] bg-[#14052b] border-t border-pink-400/60
                shadow-[0_-10px_40px_rgba(255,95,168,0.35)]"></div>
    <div class="absolute -inset-x-1/2 bottom-0 h-[32vh] origin-top opacity-60
                bg-[linear-gradient(rgba(255,95,168,0.55)_1px,transparent_1px),linear-gradient(90deg,rgba(255,95,168,0.55)_1px,transparent_1px)]
                bg-[size:60px_60px]
                [transform:perspective(260px)_rotateX(62deg)_translateY(0)]"></div>
</div>

<!-- Навигация: матовое стекло, мягкий розовый контур.
     relative z-50 — чтобы выпадающие меню были поверх контента ниже (это и был баг) -->
<nav class="relative z-50 mx-auto mt-5 flex max-w-[80vw] items-center justify-between px-6 py-2
                bg-[#1a0633]/50 backdrop-blur-md
                border border-pink-400/30 rounded-xl
                shadow-[0_0_30px_-8px_rgba(255,95,168,0.45)]">

    <!-- Левая часть: логотип + документация + ссылки -->
    <div class="flex items-center gap-8">
        <a href="/" class="transition-transform duration-300 hover:scale-105">
            <img class="max-h-10 drop-shadow-[0_0_12px_rgba(255,95,168,0.55)]" src="{{ resource('Images/Neo.png') }}" alt="logotype of neo">
        </a>

        <ul class="flex items-center gap-8 text-[14px] font-medium tracking-wide">
            <!-- Выпадающее меню: Документация -->
            <li class="relative group">
                <button type="button" class="flex items-center gap-2 py-1.5 px-4 rounded-lg cursor-pointer
                                   bg-white/[0.03] border border-fuchsia-400/30 text-fuchsia-100
                                   transition-all duration-300
                                   hover:border-pink-400/70 hover:text-pink-200 hover:shadow-[0_0_16px_rgba(255,95,168,0.35)]">
                    Документация
                    <span class="text-sm text-orange-300 transition-transform duration-300 group-hover:rotate-180 group-focus-within:rotate-180 group-hover:text-pink-300">
                        <i class="fas fa-sort-down mb-1"></i>
                    </span>
                </button>

                <!-- Внешний блок с pt-2 — невидимый «мостик», по которому курсор не теряет hover -->
                <div class="invisible absolute left-0 top-full z-50 min-w-44 pt-2
                            translate-y-2 opacity-0 transition-all duration-300
                            group-hover:visible group-hover:translate-y-0 group-hover:opacity-100
                            group-focus-within:visible group-focus-within:translate-y-0 group-focus-within:opacity-100">
                    <ul class="rounded-xl border border-pink-400/30 bg-[#1a0633]/90 backdrop-blur-xl p-2 shadow-[0_10px_30px_rgba(20,5,43,0.7)]">
                        <li>
                            <a href="/docs/api" class="block rounded-lg px-4 py-2 text-base text-fuchsia-100 transition-all duration-200 hover:bg-gradient-to-r hover:from-pink-500/25 hover:to-orange-400/10 hover:text-pink-200 hover:translate-x-1">
                                API
                            </a>
                        </li>
                        <li>
                            <a href="/docs" class="block rounded-lg px-4 py-2 text-base text-fuchsia-100 transition-all duration-200 hover:bg-gradient-to-r hover:from-pink-500/25 hover:to-orange-400/10 hover:text-pink-200 hover:translate-x-1">
                                Документация
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Выпадающее меню: Ссылки -->
            <li class="relative group">
                <button type="button" class="flex items-center gap-2 py-1.5 px-4 rounded-lg cursor-pointer
                                   bg-white/[0.03] border border-fuchsia-400/30 text-fuchsia-100
                                   transition-all duration-300
                                   hover:border-pink-400/70 hover:text-pink-200 hover:shadow-[0_0_16px_rgba(255,95,168,0.35)]">
                    Ссылки
                    <span class="text-sm text-orange-300 transition-transform duration-300 group-hover:rotate-180 group-focus-within:rotate-180 group-hover:text-pink-300">
                        <i class="fas fa-sort-down mb-1"></i>
                    </span>
                </button>

                <div class="invisible absolute left-0 top-full z-50 min-w-36 pt-2
                            translate-y-2 opacity-0 transition-all duration-300
                            group-hover:visible group-hover:translate-y-0 group-hover:opacity-100
                            group-focus-within:visible group-focus-within:translate-y-0 group-focus-within:opacity-100">
                    <ul class="rounded-xl border border-pink-400/30 bg-[#1a0633]/90 backdrop-blur-xl p-2 shadow-[0_10px_30px_rgba(20,5,43,0.7)]">
                        <li>
                            <a href="https://github.com/wemmbley/neo" target="_blank" rel="noopener noreferrer" class="block rounded-lg px-4 py-2 text-base text-fuchsia-100 transition-all duration-200 hover:bg-gradient-to-r hover:from-pink-500/25 hover:to-orange-400/10 hover:text-pink-200 hover:translate-x-1">
                                GitHub
                            </a>
                        </li>
                        <li>
                            <a href="https://t.me/" target="_blank" rel="noopener noreferrer" class="block rounded-lg px-4 py-2 text-base text-fuchsia-100 transition-all duration-200 hover:bg-gradient-to-r hover:from-pink-500/25 hover:to-orange-400/10 hover:text-pink-200 hover:translate-x-1">
                                Telegram
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>
    </div>

    <!-- Правая часть: авторизация -->
    <button type="button" class="flex items-center gap-2 py-1.5 px-5 rounded-lg cursor-pointer text-[14px] font-semibold tracking-wide
                       bg-gradient-to-r from-pink-500 via-fuchsia-500 to-orange-400
                       border border-pink-200/40 text-white
                       shadow-[0_0_20px_-4px_rgba(255,95,168,0.65)]
                       transition-all duration-300
                       hover:from-orange-400 hover:via-pink-500 hover:to-fuchsia-500 hover:shadow-[0_0_28px_rgba(255,150,100,0.55)] active:scale-95">
        Авторизация
    </button>
</nav>

<!-- Главный блок -->
<div class="mx-auto mt-10 max-w-[60vw]">
    <!-- Заголовок: градиент заката -->
    <h1 class="text-3xl font-bold tracking-tight bg-gradient-to-r from-pink-400 via-fuchsia-400 to-orange-300 bg-clip-text text-transparent drop-shadow-[0_2px_14px_rgba(255,95,168,0.45)]">
        Добро пожаловать в Neo!
    </h1>

    <!-- Контейнер для арта с розово-оранжевым свечением -->
    <div class="relative mt-6 group">
        <div class="absolute -inset-1 rounded-2xl bg-gradient-to-r from-pink-500/40 via-fuchsia-500/35 to-orange-400/35 blur-xl opacity-75 transition duration-500 group-hover:opacity-100"></div>
        <div class="relative overflow-hidden rounded-2xl border border-pink-300/20 bg-[#1a0633]/60 backdrop-blur-sm">
            <img class="w-full h-auto object-cover transition-transform duration-700 group-hover:scale-[1.01]" src="{{ resource('Images/NeoArt.png') }}" alt="art of neo">
        </div>
    </div>
</div>

</body>

@include('Layouts.Footer')