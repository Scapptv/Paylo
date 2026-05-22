<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import Pill from '@/Components/Pill.vue';
import LedgerTypeBadge from '@/Components/LedgerTypeBadge.vue';
import { useFormat } from '@/Composables/useFormat.js';

const props = defineProps({
    stats:          { type: Object, required: true },
    recentEntries:  { type: Array,  required: true },
    topMerchants:   { type: Array,  required: true },
});

const { azn, aznCompact, compact, relativeTime } = useFormat();
</script>

<template>
    <Head title="Admin Dashboard" />
    <AdminLayout breadcrumb="Dashboard">

        <!-- Hero -->
        <div class="mb-10">
            <div class="font-mono text-[10px] uppercase tracking-widest text-muted">01 / Global Visibility</div>
            <h1 class="font-serif font-light text-5xl mt-3 tracking-tight">
                Sistem <em class="italic font-semibold text-accent">salnaməsi</em>
            </h1>
            <p class="mt-3 text-sm text-muted max-w-2xl">
                Bütün ledger entry-lər, per-merchant bucket-lər, fraud signalları və settlement vəziyyəti.
            </p>
        </div>

        <!-- Stat grid -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-5 mb-10">
            <StatCard
                accent
                label="Total Locked Bonus"
                :value="aznCompact(stats.totalLocked)"
                sub="Bütün bucket-lərdə cəm"
                :delta="'+8.4%'"
                trend="up"
            />
            <StatCard
                label="Active Merchants"
                :value="stats.totalMerchants"
                :sub="`${stats.pendingMerchants} pending review`"
            />
            <StatCard
                label="Customers"
                :value="compact(stats.totalUsers)"
                :sub="`${compact(stats.totalBuckets)} aktiv bucket`"
            />
            <StatCard
                label="Ledger / 24h"
                :value="compact(stats.last24hEntries)"
                :sub="`${compact(stats.totalLedger)} cəm`"
            />
        </div>

        <!-- Two columns: recent ledger + top merchants -->
        <div class="grid lg:grid-cols-[1.4fr_1fr] gap-6">

            <!-- Ledger preview -->
            <div class="card">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <div class="section-title mb-0">Son Ledger Entry-lər</div>
                        <div class="font-serif text-xl mt-1">Immutable Audit Stream</div>
                    </div>
                    <Link :href="route('admin.ledger')" class="btn">Bütün ledger →</Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left font-mono text-[10px] uppercase tracking-widest text-muted">
                                <th class="py-2 pr-3">Type</th>
                                <th class="py-2 pr-3">Customer</th>
                                <th class="py-2 pr-3">Merchant</th>
                                <th class="py-2 pr-3 text-right">Amount</th>
                                <th class="py-2 pr-3">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entry in recentEntries" :key="entry.id" class="border-t border-border hover:bg-surface-2">
                                <td class="py-3 pr-3">
                                    <LedgerTypeBadge :type="entry.type" />
                                </td>
                                <td class="py-3 pr-3">
                                    <div class="text-sm">{{ entry.user?.name || '—' }}</div>
                                </td>
                                <td class="py-3 pr-3">
                                    <div class="text-sm">{{ entry.merchant?.name || '—' }}</div>
                                    <div class="font-mono text-[10px] text-accent-blue">{{ entry.merchant?.code }}</div>
                                </td>
                                <td class="py-3 pr-3 text-right font-mono text-sm" :class="{
                                    'text-success': ['earn', 'adjustment'].includes(entry.type),
                                    'text-danger':  ['redeem', 'refund', 'reversal'].includes(entry.type),
                                }">
                                    {{ ['earn', 'adjustment'].includes(entry.type) ? '+' : '−' }}{{ azn(entry.amount) }}
                                </td>
                                <td class="py-3 pr-3 font-mono text-[11px] text-muted">{{ relativeTime(entry.created_at) }}</td>
                            </tr>
                            <tr v-if="!recentEntries.length">
                                <td colspan="5" class="py-10 text-center text-muted font-mono text-xs">No ledger entries yet</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Merchants -->
            <div class="card">
                <div class="section-title">Top Merchants</div>
                <div class="font-serif text-xl mt-1 mb-5">By Ledger Activity</div>

                <div class="space-y-3">
                    <Link
                        v-for="m in topMerchants"
                        :key="m.id"
                        :href="route('admin.merchants.show', m.id)"
                        class="flex items-center gap-4 p-3 border border-border hover:border-accent hover:bg-surface-2 transition"
                    >
                        <div class="w-10 h-10 flex items-center justify-center bg-surface-3 font-serif font-bold text-lg">
                            {{ m.name?.[0]?.toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium truncate">{{ m.name }}</div>
                            <div class="font-mono text-[10px] text-accent-blue">{{ m.code }} · {{ m.category }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Pill :variant="m.status === 'active' ? 'success' : m.status === 'pending' ? 'warning' : 'default'" dot>
                                {{ m.status }}
                            </Pill>
                        </div>
                    </Link>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
