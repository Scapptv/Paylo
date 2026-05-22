<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import CashierLayout from '@/Layouts/CashierLayout.vue';
import { useFormat } from '@/Composables/useFormat.js';

defineProps({
    merchant: { type: Object, required: true },
});

const { azn } = useFormat();

const step = ref('lookup'); // lookup | sale | success
const qrInput = ref('');
const lookupError = ref('');
const lookingUp = ref(false);
const customer = ref(null);
const bucket = ref(null);

const saleAmount = ref('');
const useBonus = ref(false);
const redeemAzn = ref('');
const receiptNo = ref('');

const preview = ref(null);
const processing = ref(false);
const completedTxId = ref(null);

// Lookup customer by QR
async function lookup() {
    lookupError.value = '';
    lookingUp.value = true;
    try {
        const { data } = await axios.get(route('pos.lookup', qrInput.value));
        customer.value = data.customer;
        bucket.value = data.bucket;
        step.value = 'sale';
        receiptNo.value = 'r' + Date.now().toString(36).toUpperCase();
    } catch (e) {
        lookupError.value = e.response?.data?.error || 'Tapılmadı';
    } finally {
        lookingUp.value = false;
    }
}

// AZN (float, UI input) → qəpik (integer, API). Float xətalarından qaçmaq üçün
// `Math.round` ilə dəqiq integer-ə çevrilir.
const toCents = (azn) => Math.round(parseFloat(azn || 0) * 100);

// Live preview
const fetchPreview = async () => {
    const saleCents = toCents(saleAmount.value);
    if (saleCents <= 0) {
        preview.value = null;
        return;
    }
    try {
        const { data } = await axios.post(route('pos.preview'), {
            customer_id: customer.value.id,
            sale_amount_cents: saleCents,
            use_bonus: useBonus.value,
            redeem_cents: useBonus.value ? toCents(redeemAzn.value) : 0,
        });
        preview.value = data;
    } catch (e) {
        preview.value = null;
    }
};

const onSaleAmountChange = () => fetchPreview();
const onUseBonusChange = () => fetchPreview();

const finalToPay = computed(() => {
    if (!preview.value) return 0;
    return preview.value.final_to_pay;
});

// Complete
async function complete() {
    processing.value = true;
    try {
        const redeemCents = useBonus.value
            ? (toCents(redeemAzn.value) || preview.value?.redeem_amount || 0)
            : 0;

        const { data } = await axios.post(route('pos.complete'), {
            customer_id: customer.value.id,
            sale_amount_cents: toCents(saleAmount.value),
            receipt_no: receiptNo.value,
            use_bonus: useBonus.value,
            redeem_cents: redeemCents,
        });
        completedTxId.value = data.transaction_id;
        step.value = 'success';
    } catch (e) {
        alert(e.response?.data?.message || 'Xəta baş verdi');
    } finally {
        processing.value = false;
    }
}

function reset() {
    step.value = 'lookup';
    qrInput.value = '';
    customer.value = null;
    bucket.value = null;
    saleAmount.value = '';
    useBonus.value = false;
    redeemAzn.value = '';
    preview.value = null;
    completedTxId.value = null;
}
</script>

<template>
    <Head title="POS Sale" />
    <CashierLayout>

        <div class="max-w-2xl mx-auto">

            <!-- Header -->
            <div class="text-center mb-10">
                <div class="section-title">04 / Point of Sale</div>
                <h1 class="font-serif font-light text-4xl mt-2 tracking-tight">
                    Yeni <em class="italic font-semibold text-accent">satış</em>
                </h1>
                <p class="mt-2 text-sm text-muted">{{ merchant.name }} · {{ merchant.code }}</p>
            </div>

            <!-- Step 1: Lookup -->
            <div v-if="step === 'lookup'" class="card">
                <div class="section-title">Step 1 / Müştəri tanı</div>
                <h2 class="font-serif text-2xl mb-6">QR kodu skan və ya əl ilə daxil et</h2>

                <form @submit.prevent="lookup" class="space-y-4">
                    <input
                        v-model="qrInput"
                        type="text"
                        autofocus
                        placeholder="qr_abc123..."
                        class="input text-lg font-mono"
                    />
                    <div v-if="lookupError" class="text-danger text-sm font-mono">{{ lookupError }}</div>
                    <button type="submit" :disabled="!qrInput || lookingUp" class="btn btn-primary w-full py-4 text-sm justify-center">
                        {{ lookingUp ? 'Axtarılır...' : 'Müştərini tap →' }}
                    </button>
                </form>

                <div class="mt-8 pt-6 border-t border-border font-mono text-[11px] text-muted">
                    Yaxud üzünə baxaraq müştərinin telefon nömrəsi ilə də axtarış mümkündür.
                </div>
            </div>

            <!-- Step 2: Sale -->
            <div v-if="step === 'sale'" class="space-y-5">

                <!-- Customer card -->
                <div class="card bg-gradient-to-br from-surface to-accent/5">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 flex items-center justify-center bg-accent text-bg font-serif font-bold text-2xl">
                            {{ customer.name?.[0]?.toUpperCase() }}
                        </div>
                        <div class="flex-1">
                            <div class="font-serif text-xl">{{ customer.name }}</div>
                            <div class="font-mono text-[11px] text-accent-blue">{{ customer.qr }}</div>
                        </div>
                        <div class="text-right">
                            <div class="section-title mb-1">Bu merchant-da bucket</div>
                            <div class="font-serif font-semibold text-2xl text-accent">{{ azn(bucket.balance) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Sale form -->
                <div class="card">
                    <div class="section-title">Step 2 / Satış məbləği</div>

                    <div class="mt-4">
                        <label class="font-mono text-[10px] uppercase tracking-widest text-muted mb-2 block">Satış (AZN)</label>
                        <input
                            v-model="saleAmount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="0.00"
                            class="input text-2xl font-mono"
                            @input="onSaleAmountChange"
                        />
                    </div>

                    <label class="mt-5 flex items-center gap-3 cursor-pointer">
                        <input v-model="useBonus" type="checkbox" class="w-4 h-4 bg-surface border-border text-accent" @change="onUseBonusChange" />
                        <span class="text-sm">Bonus istifadə et (yalnız bu merchant)</span>
                    </label>

                    <div v-if="useBonus" class="mt-4">
                        <label class="font-mono text-[10px] uppercase tracking-widest text-muted mb-2 block">İstifadə (AZN)</label>
                        <input
                            v-model="redeemAzn"
                            type="number"
                            step="0.01"
                            min="0"
                            :max="bucket.balance / 100"
                            :placeholder="(bucket.balance / 100).toFixed(2)"
                            class="input font-mono"
                            @input="onSaleAmountChange"
                        />
                    </div>
                </div>

                <!-- Preview -->
                <div v-if="preview" class="card bg-surface-2">
                    <div class="section-title">Step 3 / Önizləmə</div>
                    <div class="mt-3 space-y-3 font-mono text-sm">
                        <div class="flex justify-between"><span class="text-muted">Satış</span><span>{{ azn(preview.sale_amount) }}</span></div>
                        <div v-if="preview.redeem_amount" class="flex justify-between text-danger"><span>− Xərclənən bonus</span><span>−{{ azn(preview.redeem_amount) }}</span></div>
                        <div class="flex justify-between pt-3 border-t border-border text-lg"><span>Ödəniləcək</span><span class="text-accent">{{ azn(preview.final_to_pay) }}</span></div>
                        <div class="flex justify-between text-success pt-3 border-t border-border"><span>+ Qazanılacaq bonus</span><span>+{{ azn(preview.earn_amount) }}</span></div>
                        <div class="flex justify-between text-xs text-muted"><span>Yeni bucket balansı</span><span>{{ azn(preview.projected_balance) }}</span></div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button @click="reset" class="btn flex-1 justify-center py-4">↺ Yenidən</button>
                    <button @click="complete" :disabled="!preview || processing" class="btn btn-primary flex-[2] justify-center py-4">
                        {{ processing ? 'Yazılır...' : 'Satışı tamamla →' }}
                    </button>
                </div>
            </div>

            <!-- Step 3: Success -->
            <div v-if="step === 'success'" class="card text-center py-12">
                <div class="text-6xl text-success mb-6">✓</div>
                <h2 class="font-serif text-3xl mb-3">Satış uğurlu</h2>
                <p class="text-muted font-mono text-xs mb-2">Receipt: {{ receiptNo }}</p>
                <p class="text-muted text-sm mb-8">Ledger entry-lər yazıldı, bucket yeniləndi.</p>
                <button @click="reset" class="btn btn-primary px-12 py-4">+ Yeni Satış</button>
            </div>

        </div>

    </CashierLayout>
</template>
