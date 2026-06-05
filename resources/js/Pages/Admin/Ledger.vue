<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch, computed } from 'vue';
import { debounce } from '@/Composables/useDebounce.js';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LedgerTypeBadge from '@/Components/LedgerTypeBadge.vue';
import { useFormat } from '@/Composables/useFormat.js';

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
    types:   { type: Array,  required: true },
});

const { azn, date } = useFormat();

const state = reactive({
    type:        props.filters.type || '',
    merchant_id: props.filters.merchant_id || '',
    q:           props.filters.q || '',
});

const applyFilters = debounce(() => {
    router.get(route('admin.ledger'), state, { preserveState: true, replace: true });
}, 250);

watch(state, applyFilters, { deep: true });

// Roadmap Phase 2.3: type preset-ində (Redemptions/Refunds) breadcrumb həmin tipin
// adını göstərir; əks halda sadəcə "Ledger".
const typeLabel = computed(() =>
    props.filters.type
        ? (props.types.find((t) => t.value === props.filters.type)?.label || 'Ledger')
        : 'Ledger'
);
</script>

<template>
    <Head title="Ledger" />
    <AdminLayout :breadcrumb="typeLabel">

        <div class="flex items-center justify-between mb-8">
            <div>
                <div class="section-title">Immutable Audit Stream</div>
                <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                    Ledger <em class="italic font-semibold text-accent">explorer</em>
                </h1>
            </div>
            <div class="font-mono text-xs text-muted">
                <span class="text-accent">{{ entries.total?.toLocaleString() || 0 }}</span> entries matching
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-6 flex flex-wrap items-center gap-3">
            <input
                v-model="state.q"
                type="search"
                placeholder="uid, receipt, ref..."
                class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs flex-1 min-w-[200px] focus:outline-none focus:border-accent"
            />

            <select v-model="state.type" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option value="">All types</option>
                <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>

            <button @click="state.q = ''; state.type = ''; state.merchant_id = ''" class="btn">Sıfırla</button>
        </div>

        <!-- Table -->
        <div class="card p-0 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-surface-2">
                    <tr class="text-left font-mono text-[10px] uppercase tracking-widest text-muted">
                        <th class="py-3 px-4">UID</th>
                        <th class="py-3 px-4">Type</th>
                        <th class="py-3 px-4">Customer</th>
                        <th class="py-3 px-4">Merchant</th>
                        <th class="py-3 px-4 text-right">Amount</th>
                        <th class="py-3 px-4 text-right">Balance After</th>
                        <th class="py-3 px-4">Time</th>
                        <th class="py-3 px-4 w-12"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries.data" :key="entry.id" class="border-t border-border hover:bg-surface-2 transition">
                        <td class="py-3 px-4 font-mono text-[11px] text-accent-blue">{{ entry.uid }}</td>
                        <td class="py-3 px-4"><LedgerTypeBadge :type="entry.type" /></td>
                        <td class="py-3 px-4">
                            <div>{{ entry.user?.name || '—' }}</div>
                            <div class="font-mono text-[10px] text-muted">{{ entry.user?.email }}</div>
                        </td>
                        <td class="py-3 px-4">
                            <div>{{ entry.merchant?.name || '—' }}</div>
                            <div class="font-mono text-[10px] text-accent-blue">{{ entry.merchant?.code }}</div>
                        </td>
                        <td class="py-3 px-4 text-right font-mono text-sm" :class="{
                            'text-success': ['earn','adjustment'].includes(entry.type),
                            'text-danger':  ['redeem','refund','reversal'].includes(entry.type),
                        }">
                            {{ ['earn','adjustment'].includes(entry.type) ? '+' : '−' }}{{ azn(entry.amount) }}
                        </td>
                        <td class="py-3 px-4 text-right font-mono text-xs text-muted">{{ azn(entry.balance_after) }}</td>
                        <td class="py-3 px-4 font-mono text-[11px] text-muted">{{ date(entry.created_at) }}</td>
                        <td class="py-3 px-4 text-right">
                            <Link :href="route('admin.ledger.show', entry.id)" class="text-accent hover:underline font-mono text-xs">→</Link>
                        </td>
                    </tr>
                    <tr v-if="!entries.data?.length">
                        <td colspan="8" class="py-12 text-center text-muted font-mono text-xs">No entries match filters</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="entries.last_page > 1" class="mt-6 flex items-center justify-between font-mono text-xs text-muted">
            <div>
                Səhifə <span class="text-accent">{{ entries.current_page }}</span>
                / {{ entries.last_page }}
            </div>
            <div class="flex gap-2">
                <Link
                    v-for="link in entries.links"
                    :key="link.label"
                    :href="link.url"
                    v-html="link.label"
                    class="px-3 py-2 border border-border hover:border-accent transition"
                    :class="{
                        'bg-accent text-bg border-accent': link.active,
                        'opacity-40 pointer-events-none': !link.url,
                    }"
                />
            </div>
        </div>

    </AdminLayout>
</template>
