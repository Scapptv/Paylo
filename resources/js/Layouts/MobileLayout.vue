<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';

const page = usePage();
const logout = () => router.post(route('logout'));
</script>

<template>
    <div class="min-h-screen pb-24 grain">
        <header class="px-5 py-5 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="brand-dot" />
                <span class="font-serif font-extrabold text-lg">Paylo</span>
            </div>
            <button @click="logout" class="text-xs font-mono uppercase tracking-wider text-muted hover:text-danger">
                Çıxış
            </button>
        </header>

        <main class="px-5">
            <slot />
        </main>

        <!-- Mobile bottom nav -->
        <nav class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border">
            <div class="grid grid-cols-4 max-w-md mx-auto">
                <Link :href="route('user.wallet')" class="flex flex-col items-center gap-1 py-3 text-[10px] font-mono uppercase tracking-wider hover:text-accent" :class="page.url === '/wallet' ? 'text-accent' : 'text-muted'">
                    <span class="text-lg">◫</span>
                    <span>Wallet</span>
                </Link>
                <a href="#" class="flex flex-col items-center gap-1 py-3 text-muted text-[10px] font-mono uppercase tracking-wider">
                    <span class="text-lg">⊞</span>
                    <span>Mağaza</span>
                </a>
                <a href="#" class="flex flex-col items-center gap-1 py-3 text-muted text-[10px] font-mono uppercase tracking-wider">
                    <span class="text-lg">▦</span>
                    <span>QR</span>
                </a>
                <a href="#" class="flex flex-col items-center gap-1 py-3 text-muted text-[10px] font-mono uppercase tracking-wider">
                    <span class="text-lg">◌</span>
                    <span>Mən</span>
                </a>
            </div>
        </nav>
    </div>
</template>
