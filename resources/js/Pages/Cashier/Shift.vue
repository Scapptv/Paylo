<script setup>
import { Head, Link } from '@inertiajs/vue3';
import CashierLayout from '@/Layouts/CashierLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import { useFormat } from '@/Composables/useFormat.js';

defineProps({
    cashier:            { type: Object, required: true },
    shiftStats:         { type: Object, required: true },
    recentTransactions: { type: Array,  required: true },
});

const { azn, aznCompact, relativeTime } = useFormat();
</script>

<template>
    <Head title="Shift" />
    <CashierLayout>

        <!-- Header -->
        <div class="flex items-end justify-between mb-8">
            <div>
                <div class="section-title">05 / Field Operations</div>
                <h1 class="font-serif font-light text-4xl mt-2 tracking-tight">
                    Salam, <em class="italic font-semibold text-accent">{{ cashier.name }}</em>
                </h1>
                <p class="mt-2 text-sm text-muted">
                    Bu gün {{ cashier.merchant.name }}-də. Aktiv shift davam edir.
                </p>
            </div>
            <Link :href="route('pos.sale')" class="btn btn-primary">+ Yeni Satış</Link>
        </div>

        <!-- Shift stats -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-5 mb-10">
            <StatCard accent label="Bu Gün Tranzaksiya" :value="shiftStats.transactions" sub="aktiv shift" />
            <StatCard label="Cəm Satış" :value="aznCompact(shiftStats.totalSales)" />
            <StatCard label="Verilən Bonus" :value="aznCompact(shiftStats.totalEarned)" />
            <StatCard label="Xərclənən Bonus" :value="aznCompact(shiftStats.totalRedeemed)" />
        </div>

        <!-- Recent transactions -->
        <div class="card">
            <div class="section-title">Bu Shift-in Tranzaksiyaları</div>
            <div class="font-serif text-xl mt-1 mb-5">{{ recentTransactions.length }} əməliyyat</div>

            <div class="space-y-2">
                <div
                    v-for="tx in recentTransactions"
                    :key="tx.id"
                    class="flex items-center gap-4 p-4 border border-border hover:border-accent transition"
                >
                    <div class="font-mono text-[11px] text-accent-blue w-32">{{ tx.receipt_no }}</div>
                    <div class="flex-1">
                        <div class="text-sm">{{ tx.customer?.name }}</div>
                        <div class="font-mono text-[10px] text-muted">{{ relativeTime(tx.occurred_at) }}</div>
                    </div>
                    <div class="text-right">
                        <div class="font-mono text-sm">{{ azn(tx.sale_amount) }}</div>
                        <div class="font-mono text-[10px]">
                            <span class="text-success">+{{ azn(tx.earned_amount) }}</span>
                            <span v-if="tx.redeemed_amount" class="text-danger ml-2">−{{ azn(tx.redeemed_amount) }}</span>
                        </div>
                    </div>
                </div>
                <div v-if="!recentTransactions.length" class="py-16 text-center">
                    <div class="font-mono text-xs text-muted mb-3">Hələ heç bir tranzaksiya yoxdur</div>
                    <Link :href="route('pos.sale')" class="btn btn-primary">İlk satışı et →</Link>
                </div>
            </div>
        </div>

    </CashierLayout>
</template>
