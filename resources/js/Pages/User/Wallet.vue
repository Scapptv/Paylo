<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import MobileLayout from '@/Layouts/MobileLayout.vue';
import LedgerTypeBadge from '@/Components/LedgerTypeBadge.vue';
import { useFormat } from '@/Composables/useFormat.js';

defineProps({
    customer:       { type: Object, required: true },
    totalBalance:   { type: Number, required: true },
    buckets:        { type: Array,  required: true },
    recentEntries:  { type: Array,  required: true },
});

const { azn, relativeTime } = useFormat();

const expanded = ref(false);
const showQr = ref(false);
</script>

<template>
    <Head title="Mənim Wallet-im" />
    <MobileLayout>

        <!-- Greeting -->
        <div class="mt-2">
            <div class="font-mono text-[10px] uppercase tracking-widest text-muted">Xoş gəldin</div>
            <h1 class="font-serif text-3xl mt-1 leading-tight">{{ customer.name }}</h1>
        </div>

        <!-- Total balance card -->
        <div
            class="mt-6 p-6 border border-border bg-surface relative overflow-hidden cursor-pointer"
            @click="expanded = !expanded"
        >
            <div class="absolute inset-0 opacity-30 pointer-events-none"
                 style="background: radial-gradient(circle at 80% 10%, rgba(200,255,61,0.25), transparent 60%)"></div>

            <div class="relative z-10">
                <div class="font-mono text-[10px] uppercase tracking-widest text-muted">Cəm Bonus</div>
                <div class="mt-2 font-serif text-5xl font-light tracking-tight text-accent">
                    {{ azn(totalBalance) }}
                </div>
                <div class="mt-3 font-mono text-[11px] text-muted flex items-center gap-2">
                    <span>{{ buckets.length }} fərqli merchant-da</span>
                    <span class="text-accent">{{ expanded ? '▲ gizlət' : '▼ breakdown' }}</span>
                </div>
            </div>
        </div>

        <!-- Per-merchant breakdown -->
        <transition name="fade">
            <div v-if="expanded" class="mt-3 space-y-2">
                <div
                    v-for="b in buckets"
                    :key="b.id"
                    class="p-4 border border-border bg-surface flex items-center gap-3"
                >
                    <div class="w-10 h-10 flex items-center justify-center bg-surface-3 font-serif font-bold">
                        {{ b.merchant?.name?.[0]?.toUpperCase() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium truncate">{{ b.merchant?.name }}</div>
                        <div class="font-mono text-[10px] text-muted">{{ b.merchant?.category }}</div>
                    </div>
                    <div class="font-mono text-sm text-accent">{{ azn(b.balance) }}</div>
                </div>
                <div v-if="!buckets.length" class="py-8 text-center text-muted font-mono text-xs">
                    Hələ heç bir merchant-da bonus yoxdur
                </div>
            </div>
        </transition>

        <!-- QR action -->
        <button
            @click="showQr = !showQr"
            class="mt-6 w-full p-5 border border-accent bg-accent/10 text-accent font-mono text-xs uppercase tracking-widest hover:bg-accent hover:text-bg transition flex items-center justify-center gap-3"
        >
            <span class="text-2xl">▦</span>
            {{ showQr ? 'QR-ı gizlət' : 'QR-ı göstər' }}
        </button>

        <!-- QR Display -->
        <div v-if="showQr" class="mt-3 p-8 border border-accent bg-surface text-center">
            <div class="w-48 h-48 bg-text mx-auto flex items-center justify-center font-mono text-xs text-bg">
                [ QR CODE ]
            </div>
            <div class="mt-4 font-mono text-[11px] text-accent-blue">{{ customer.qr }}</div>
            <div class="mt-2 font-mono text-[10px] text-muted">Kassirə göstər</div>
        </div>

        <!-- Recent activity -->
        <div class="mt-10">
            <div class="flex items-center justify-between mb-4">
                <div class="section-title mb-0">Son hərəkətlər</div>
                <a href="#" class="font-mono text-[10px] uppercase tracking-widest text-accent">HAMISI →</a>
            </div>

            <div class="space-y-2">
                <div
                    v-for="entry in recentEntries"
                    :key="entry.id"
                    class="p-4 border border-border bg-surface flex items-center gap-3"
                >
                    <LedgerTypeBadge :type="entry.type" />
                    <div class="flex-1 min-w-0">
                        <div class="text-sm truncate">{{ entry.merchant?.name }}</div>
                        <div class="font-mono text-[10px] text-muted">{{ relativeTime(entry.created_at) }}</div>
                    </div>
                    <div class="font-mono text-sm" :class="{
                        'text-success': ['earn','adjustment'].includes(entry.type),
                        'text-danger':  ['redeem','refund','reversal'].includes(entry.type),
                    }">
                        {{ ['earn','adjustment'].includes(entry.type) ? '+' : '−' }}{{ azn(entry.amount) }}
                    </div>
                </div>
                <div v-if="!recentEntries.length" class="py-8 text-center text-muted font-mono text-xs">
                    Hələ heç bir əməliyyat yoxdur
                </div>
            </div>
        </div>

    </MobileLayout>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: all .25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(-8px); }
</style>
