<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    merchants: { type: Array, default: () => [] },
});

const page = usePage();
const justCredited = ref(false);

const form = useForm({
    email:       '',
    merchant_id: '',
    amount_azn:  '',
    reason:      '',
});

function submit() {
    justCredited.value = false;
    form
        // AZN → qəpik (integer); backend yalnız amount_cents qəbul edir.
        .transform((data) => ({
            email:        data.email || null,
            merchant_id:  data.merchant_id,
            amount_cents: Math.round((parseFloat(data.amount_azn) || 0) * 100),
            reason:       data.reason,
        }))
        .post(route('admin.bonus-adjustments.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('email', 'amount_azn', 'reason');
                justCredited.value = true;
            },
        });
}
</script>

<template>
    <Head title="Manual Bonus Düzəlişi" />
    <AdminLayout breadcrumb="Manual Adj.">

        <Link :href="route('admin.dashboard')" class="font-mono text-xs uppercase tracking-wider text-muted hover:text-accent mb-6 inline-block">
            ← Dashboard
        </Link>

        <div class="mb-8">
            <div class="section-title">Loyalty Core</div>
            <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                Manual bonus <em class="italic font-semibold text-accent">krediti</em>
            </h1>
            <p class="text-sm text-muted mt-2 max-w-2xl">
                Müştərinin seçilmiş merchant bucket-inə bonus əlavə edir (yalnız CREDIT).
                Bonus azaltmaq üçün satışın reverse/refund yolu istifadə olunur.
                Hər əməliyyat immutable ledger-ə yazılır və audit üçün səbəb tələb edir.
            </p>
        </div>

        <!-- Uğur bildirişi (flash status varsa onu, yoxsa generic) -->
        <div v-if="justCredited || page.props.flash?.status"
             class="card max-w-3xl mb-6 border-l-2 border-l-success flex items-center gap-3">
            <span class="text-success text-lg">✓</span>
            <span class="text-sm text-text">{{ page.props.flash?.status || 'Bonus krediti uğurla edildi.' }}</span>
        </div>

        <form @submit.prevent="submit" class="card max-w-3xl space-y-6">

            <div>
                <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Müştəri e-poçtu</label>
                <input v-model="form.email" type="email" class="input" placeholder="aysel@gmail.com" autocomplete="off" />
                <div v-if="form.errors.email" class="text-xs text-danger mt-1">{{ form.errors.email }}</div>
                <div class="text-[11px] text-muted mt-1">Yalnız aktiv müştəri hesabı qəbul edilir.</div>
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Merchant</label>
                    <select v-model="form.merchant_id" class="input">
                        <option value="" disabled>— seç —</option>
                        <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.code }} · {{ m.name }}</option>
                    </select>
                    <div v-if="form.errors.merchant_id" class="text-xs text-danger mt-1">{{ form.errors.merchant_id }}</div>
                </div>
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Məbləğ (AZN)</label>
                    <input v-model="form.amount_azn" type="number" step="0.01" min="0.01" class="input" placeholder="5.00" />
                    <div v-if="form.errors.amount_cents" class="text-xs text-danger mt-1">{{ form.errors.amount_cents }}</div>
                    <div class="text-[11px] text-muted mt-1">Müsbət dəyər. Daxili olaraq qəpiyə çevrilir (×100).</div>
                </div>
            </div>

            <div>
                <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Səbəb (audit)</label>
                <textarea v-model="form.reason" rows="3" class="input" placeholder="məs. şikayət həlli / goodwill / reverse bərpası"></textarea>
                <div v-if="form.errors.reason" class="text-xs text-danger mt-1">{{ form.errors.reason }}</div>
            </div>

            <div class="pt-4 border-t border-border flex items-center justify-between">
                <Link :href="route('admin.dashboard')" class="text-sm text-muted hover:text-accent">Ləğv et</Link>
                <button type="submit" :disabled="form.processing" class="btn-primary px-6">
                    {{ form.processing ? 'Kredit edilir...' : 'Bonus kredit et' }}
                </button>
            </div>
        </form>

    </AdminLayout>
</template>
