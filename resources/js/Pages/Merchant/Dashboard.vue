<script setup>
import { Head } from '@inertiajs/vue3';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import Pill from '@/Components/Pill.vue';
import { useFormat } from '@/Composables/useFormat.js';

defineProps({
    stats:               { type: Object, required: true },
    recentTransactions:  { type: Array,  required: true },
    topCustomers:        { type: Array,  required: true },
});

const { azn, aznCompact, compact, relativeTime } = useFormat();
</script>

<template>
    <Head title="Merchant Dashboard" />
    <MerchantLayout breadcrumb="Dashboard">

        <div class="mb-10">
            <div class="section-title">02 / Merchant-scoped</div>
            <h1 class="font-serif font-light text-5xl mt-3 tracking-tight">
                Sənin <em class="italic font-semibold text-accent">dükan</em> dünyan.
            </h1>
            <p class="mt-3 text-sm text-muted max-w-2xl">
                Yalnız sənin merchant-ında baş verən hər şey: müştərilər, kampaniyalar, settlement, POS aktivliyi.
            </p>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-5 mb-10">
            <StatCard accent label="Bonus Locked" :value="aznCompact(stats.totalLocked)" sub="Cəm bucket-lərdə" />
            <StatCard label="Customers" :value="compact(stats.customers)" sub="bucket sayı" />
            <StatCard label="Earned · 30 gün" :value="aznCompact(stats.earned30d)" sub="paylaşılmış bonus" delta="+12.3%" trend="up" />
            <StatCard label="Redeemed · 30 gün" :value="aznCompact(stats.redeemed30d)" sub="xərclənmiş bonus" delta="+4.1%" trend="up" />
        </div>

        <div class="grid lg:grid-cols-[1.4fr_1fr] gap-6">

            <!-- Recent transactions -->
            <div class="card">
                <div class="section-title">Son Tranzaksiyalar</div>
                <div class="font-serif text-xl mt-1 mb-5">POS Activity</div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left font-mono text-[10px] uppercase tracking-widest text-muted">
                            <th class="py-2 pr-3">Receipt</th>
                            <th class="py-2 pr-3">Customer</th>
                            <th class="py-2 pr-3 text-right">Sale</th>
                            <th class="py-2 pr-3 text-right">+Earn</th>
                            <th class="py-2 pr-3 text-right">−Redeem</th>
                            <th class="py-2 pr-3">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tx in recentTransactions" :key="tx.id" class="border-t border-border">
                            <td class="py-3 pr-3 font-mono text-[11px] text-accent-blue">{{ tx.receipt_no }}</td>
                            <td class="py-3 pr-3">
                                <div>{{ tx.customer?.name }}</div>
                                <div class="font-mono text-[10px] text-muted">{{ tx.cashier?.name }}</div>
                            </td>
                            <td class="py-3 pr-3 text-right font-mono text-sm">{{ azn(tx.sale_amount) }}</td>
                            <td class="py-3 pr-3 text-right font-mono text-sm text-success">+{{ azn(tx.earned_amount) }}</td>
                            <td class="py-3 pr-3 text-right font-mono text-sm" :class="tx.redeemed_amount ? 'text-danger' : 'text-muted'">
                                {{ tx.redeemed_amount ? '−' + azn(tx.redeemed_amount) : '—' }}
                            </td>
                            <td class="py-3 pr-3 font-mono text-[11px] text-muted">{{ relativeTime(tx.occurred_at) }}</td>
                        </tr>
                        <tr v-if="!recentTransactions.length">
                            <td colspan="6" class="py-10 text-center text-muted font-mono text-xs">No transactions yet</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Top customers -->
            <div class="card">
                <div class="section-title">Top Müştərilər</div>
                <div class="font-serif text-xl mt-1 mb-5">By Earnings</div>

                <div class="space-y-3">
                    <div v-for="b in topCustomers" :key="b.id" class="flex items-center gap-4 p-3 border border-border">
                        <div class="w-10 h-10 flex items-center justify-center bg-surface-3 font-serif font-bold text-lg">
                            {{ b.user?.name?.[0]?.toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium truncate">{{ b.user?.name }}</div>
                            <div class="font-mono text-[10px] text-muted">{{ b.user?.phone || '—' }}</div>
                        </div>
                        <div class="text-right">
                            <div class="font-mono text-sm text-accent">{{ aznCompact(b.earned_total) }}</div>
                            <div class="font-mono text-[10px] text-muted">balance {{ azn(b.balance) }}</div>
                        </div>
                    </div>
                    <div v-if="!topCustomers.length" class="py-8 text-center text-muted font-mono text-xs">
                        No customers yet
                    </div>
                </div>
            </div>

        </div>

    </MerchantLayout>
</template>
