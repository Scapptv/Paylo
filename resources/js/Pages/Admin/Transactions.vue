<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { debounce } from '@/Composables/useDebounce.js';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pill from '@/Components/Pill.vue';

const props = defineProps({
    transactions: { type: Object, required: true },
    filters:      { type: Object, required: true },
});

const page = usePage();

const state = reactive({
    status: props.filters.status || '',
    q:      props.filters.q || '',
});

const apply = debounce(() => {
    router.get(route('admin.transactions'), state, { preserveState: true, replace: true });
}, 250);
watch(state, apply, { deep: true });

const statusVariant = { completed: 'success', reversed: 'danger', refunded: 'warning' };
const azn = (c) => (Number(c || 0) / 100).toFixed(2);
const fmtDate = (s) => s ? new Date(s).toLocaleString('az-AZ', { dateStyle: 'short', timeStyle: 'short' }) : '—';

// Reverse modal
const reverseTx = ref(null);
const reverseForm = useForm({ return_receipt_no: '', reason: '' });

function openReverse(tx) {
    reverseTx.value = tx;
    reverseForm.reset();
    reverseForm.clearErrors();
}
function submitReverse() {
    reverseForm.post(route('admin.transactions.reverse', reverseTx.value.id), {
        preserveScroll: true,
        onSuccess: () => { reverseTx.value = null; reverseForm.reset(); },
    });
}
</script>

<template>
    <Head title="Transactions" />
    <AdminLayout breadcrumb="Transactions">

        <div class="flex items-center justify-between mb-8">
            <div>
                <div class="section-title">POS sale records</div>
                <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                    <em class="italic font-semibold text-accent">Tranzaksiyalar</em>
                </h1>
            </div>
            <div class="font-mono text-xs text-muted"><span class="text-accent">{{ transactions.total }}</span> tx</div>
        </div>

        <div v-if="page.props.flash?.success" class="card mb-4 border-l-2 border-l-success text-sm text-text">✓ {{ page.props.flash.success }}</div>
        <div v-if="page.props.flash?.error" class="card mb-4 border-l-2 border-l-danger text-sm text-danger">⚠ {{ page.props.flash.error }}</div>

        <div class="card mb-6 flex flex-wrap gap-3">
            <input v-model="state.q" type="search" placeholder="receipt no..." class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs flex-1 min-w-[220px] focus:outline-none focus:border-accent" />
            <select v-model="state.status" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option value="">All status</option>
                <option value="completed">Completed</option>
                <option value="reversed">Reversed</option>
                <option value="refunded">Refunded</option>
            </select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="font-mono text-[10px] uppercase tracking-wider text-muted border-b border-border">
                        <th class="text-left py-2 px-2">Receipt</th>
                        <th class="text-left py-2 px-2">Customer</th>
                        <th class="text-left py-2 px-2">Merchant</th>
                        <th class="text-right py-2 px-2">Sale</th>
                        <th class="text-right py-2 px-2">Earn</th>
                        <th class="text-right py-2 px-2">Redeem</th>
                        <th class="text-left py-2 px-2">Status</th>
                        <th class="text-left py-2 px-2">Date</th>
                        <th class="text-right py-2 px-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="tx in transactions.data" :key="tx.id" class="border-b border-border/50 hover:bg-surface-2">
                        <td class="py-2 px-2 font-mono text-xs text-accent-blue">{{ tx.receipt_no }}</td>
                        <td class="py-2 px-2">{{ tx.customer?.name || '—' }}</td>
                        <td class="py-2 px-2 font-mono text-[11px]">{{ tx.merchant?.code }}</td>
                        <td class="py-2 px-2 text-right font-mono">{{ azn(tx.sale_amount) }}</td>
                        <td class="py-2 px-2 text-right font-mono text-success">{{ azn(tx.earned_amount) }}</td>
                        <td class="py-2 px-2 text-right font-mono text-warning">{{ azn(tx.redeemed_amount) }}</td>
                        <td class="py-2 px-2"><Pill :variant="statusVariant[tx.status] || 'default'" dot>{{ tx.status }}</Pill></td>
                        <td class="py-2 px-2 font-mono text-[11px] text-muted">{{ fmtDate(tx.occurred_at) }}</td>
                        <td class="py-2 px-2 text-right">
                            <button v-if="tx.status === 'completed'" @click="openReverse(tx)"
                                    class="font-mono text-[11px] uppercase tracking-wider text-danger hover:underline">Reverse</button>
                        </td>
                    </tr>
                    <tr v-if="!transactions.data?.length">
                        <td colspan="9" class="py-16 text-center text-muted font-mono text-xs">Tranzaksiya tapılmadı</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="transactions.last_page > 1" class="flex items-center justify-between mt-4 font-mono text-xs text-muted">
            <span>{{ transactions.from }}–{{ transactions.to }} / {{ transactions.total }}</span>
            <div class="flex gap-2">
                <button :disabled="!transactions.prev_page_url" @click="router.get(transactions.prev_page_url, {}, { preserveState: true })"
                        class="px-3 py-1 border border-border disabled:opacity-40 hover:border-accent">← Prev</button>
                <button :disabled="!transactions.next_page_url" @click="router.get(transactions.next_page_url, {}, { preserveState: true })"
                        class="px-3 py-1 border border-border disabled:opacity-40 hover:border-accent">Next →</button>
            </div>
        </div>

        <!-- Reverse modal -->
        <div v-if="reverseTx" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="reverseTx = null">
            <div class="card max-w-lg w-full">
                <h2 class="font-serif text-xl font-semibold mb-1">Tranzaksiyanı reverse et</h2>
                <p class="text-xs text-muted mb-4 font-mono">{{ reverseTx.receipt_no }} · {{ azn(reverseTx.sale_amount) }} AZN · {{ reverseTx.customer?.name }}</p>
                <form @submit.prevent="submitReverse" class="space-y-4">
                    <div>
                        <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Qaytarma qəbzi №</label>
                        <input v-model="reverseForm.return_receipt_no" type="text" class="input" placeholder="RET-2026-00001" />
                        <div v-if="reverseForm.errors.return_receipt_no" class="text-xs text-danger mt-1">{{ reverseForm.errors.return_receipt_no }}</div>
                    </div>
                    <div>
                        <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Səbəb (audit)</label>
                        <textarea v-model="reverseForm.reason" rows="2" class="input" placeholder="müştəri qaytardı / səhv satış"></textarea>
                        <div v-if="reverseForm.errors.reason" class="text-xs text-danger mt-1">{{ reverseForm.errors.reason }}</div>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t border-border">
                        <button type="button" @click="reverseTx = null" class="text-sm text-muted hover:text-accent">Ləğv et</button>
                        <button type="submit" :disabled="reverseForm.processing" class="btn-primary px-5">
                            {{ reverseForm.processing ? 'Reverse edilir...' : 'Təsdiqlə və reverse et' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </AdminLayout>
</template>
