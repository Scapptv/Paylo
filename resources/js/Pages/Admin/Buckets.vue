<script setup>
import { reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { debounce } from '@/Composables/useDebounce.js';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    buckets:     { type: Object, required: true },
    merchants:   { type: Array, default: () => [] },
    filters:     { type: Object, required: true },
    totalLocked: { type: Number, default: 0 },
});

const state = reactive({
    merchant_id: props.filters.merchant_id || '',
    q:           props.filters.q || '',
});

const apply = debounce(() => {
    router.get(route('admin.buckets'), state, { preserveState: true, replace: true });
}, 250);
watch(state, apply, { deep: true });

const azn = (c) => (Number(c || 0) / 100).toFixed(2);
const fmtDate = (s) => s ? new Date(s).toLocaleDateString('az-AZ', { dateStyle: 'medium' }) : '—';
</script>

<template>
    <Head title="Buckets" />
    <AdminLayout breadcrumb="Buckets">

        <div class="flex items-center justify-between mb-8">
            <div>
                <div class="section-title">Per-merchant balances</div>
                <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                    Bonus <em class="italic font-semibold text-accent">bucket-lər</em>
                </h1>
            </div>
            <div class="text-right">
                <div class="font-mono text-[10px] uppercase text-muted">Cəm bloklanmış</div>
                <div class="font-serif text-2xl text-accent">{{ azn(totalLocked) }} <span class="text-sm text-muted">AZN</span></div>
                <div class="font-mono text-[11px] text-muted">{{ buckets.total }} bucket</div>
            </div>
        </div>

        <div class="card mb-6 flex flex-wrap gap-3">
            <input v-model="state.q" type="search" placeholder="müştəri ad / email..." class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs flex-1 min-w-[240px] focus:outline-none focus:border-accent" />
            <select v-model="state.merchant_id" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option value="">All merchants</option>
                <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.code }} · {{ m.name }}</option>
            </select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="font-mono text-[10px] uppercase tracking-wider text-muted border-b border-border">
                        <th class="text-left py-2 px-2">Müştəri</th>
                        <th class="text-left py-2 px-2">Merchant</th>
                        <th class="text-right py-2 px-2">Balance</th>
                        <th class="text-right py-2 px-2">Earned</th>
                        <th class="text-right py-2 px-2">Redeemed</th>
                        <th class="text-right py-2 px-2">Expired</th>
                        <th class="text-left py-2 px-2">Son aktivlik</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in buckets.data" :key="b.id" class="border-b border-border/50 hover:bg-surface-2">
                        <td class="py-2 px-2">
                            <div>{{ b.user?.name || '—' }}</div>
                            <div class="font-mono text-[10px] text-muted">{{ b.user?.email }}</div>
                        </td>
                        <td class="py-2 px-2 font-mono text-[11px]">{{ b.merchant?.code }} · {{ b.merchant?.name }}</td>
                        <td class="py-2 px-2 text-right font-mono text-accent">{{ azn(b.balance) }}</td>
                        <td class="py-2 px-2 text-right font-mono text-success">{{ azn(b.earned_total) }}</td>
                        <td class="py-2 px-2 text-right font-mono text-warning">{{ azn(b.redeemed_total) }}</td>
                        <td class="py-2 px-2 text-right font-mono text-muted">{{ azn(b.expired_total) }}</td>
                        <td class="py-2 px-2 font-mono text-[11px] text-muted">{{ fmtDate(b.last_activity_at) }}</td>
                    </tr>
                    <tr v-if="!buckets.data?.length">
                        <td colspan="7" class="py-16 text-center text-muted font-mono text-xs">Bucket tapılmadı</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="buckets.last_page > 1" class="flex items-center justify-between mt-4 font-mono text-xs text-muted">
            <span>{{ buckets.from }}–{{ buckets.to }} / {{ buckets.total }}</span>
            <div class="flex gap-2">
                <button :disabled="!buckets.prev_page_url" @click="router.get(buckets.prev_page_url, {}, { preserveState: true })"
                        class="px-3 py-1 border border-border disabled:opacity-40 hover:border-accent">← Prev</button>
                <button :disabled="!buckets.next_page_url" @click="router.get(buckets.next_page_url, {}, { preserveState: true })"
                        class="px-3 py-1 border border-border disabled:opacity-40 hover:border-accent">Next →</button>
            </div>
        </div>

    </AdminLayout>
</template>
