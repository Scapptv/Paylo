<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import MerchantLayout from '@/Layouts/MerchantLayout.vue';

const props = defineProps({
    mode:  { type: String, required: true },
    staff: { type: Object, default: null },
});

const isEdit = props.mode === 'edit';

const form = useForm({
    name:                  props.staff?.name      || '',
    email:                 props.staff?.email     || '',
    phone:                 props.staff?.phone     || '',
    role:                  props.staff?.role      || 'cashier',
    is_active:             props.staff?.is_active ?? true,
    password:              '',
    password_confirmation: '',
});

function submit() {
    if (isEdit) {
        form
            .transform(({ password, password_confirmation, ...rest }) => rest)
            .put(route('merchant.staff.update', props.staff.id));
    } else {
        form.post(route('merchant.staff.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? `Edit ${staff.name}` : 'Yeni işçi'" />
    <MerchantLayout :breadcrumb="isEdit ? 'Staff Edit' : 'Yeni işçi'">

        <Link :href="route('merchant.staff')" class="font-mono text-xs uppercase tracking-wider text-muted hover:text-accent mb-6 inline-block">
            ← Bütün işçilər
        </Link>

        <div class="mb-8">
            <h1 class="font-serif font-light text-4xl tracking-tight">
                <em class="italic font-semibold text-accent">{{ isEdit ? 'Redaktə et' : 'Yeni işçi' }}</em>
            </h1>
        </div>

        <form @submit.prevent="submit" class="card max-w-xl space-y-5">

            <div>
                <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Ad soyad</label>
                <input v-model="form.name" type="text" class="input" required />
                <div v-if="form.errors.name" class="text-xs text-danger mt-1">{{ form.errors.name }}</div>
            </div>

            <div>
                <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Email</label>
                <input v-model="form.email" type="email" class="input" required />
                <div v-if="form.errors.email" class="text-xs text-danger mt-1">{{ form.errors.email }}</div>
            </div>

            <div>
                <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Telefon (opsional)</label>
                <input v-model="form.phone" type="tel" class="input" placeholder="+994501234567" />
                <div v-if="form.errors.phone" class="text-xs text-danger mt-1">{{ form.errors.phone }}</div>
            </div>

            <div>
                <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Rol</label>
                <select v-model="form.role" class="input">
                    <option value="cashier">Cashier (kassir)</option>
                    <option value="merchant_staff">Merchant Staff (idarəçi)</option>
                </select>
                <div v-if="form.errors.role" class="text-xs text-danger mt-1">{{ form.errors.role }}</div>
                <div class="text-[11px] text-muted mt-1">
                    Cashier: yalnız satış. Merchant Staff: reverse + dashboard.
                </div>
            </div>

            <div v-if="!isEdit" class="space-y-5 pt-4 border-t border-border">
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Şifrə</label>
                    <input v-model="form.password" type="password" class="input" required autocomplete="new-password" />
                    <div v-if="form.errors.password" class="text-xs text-danger mt-1">{{ form.errors.password }}</div>
                </div>
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Şifrə təsdiqi</label>
                    <input v-model="form.password_confirmation" type="password" class="input" required autocomplete="new-password" />
                </div>
            </div>

            <div v-if="isEdit" class="flex items-center gap-2">
                <input id="is_active" v-model="form.is_active" type="checkbox" />
                <label for="is_active" class="text-sm">Aktiv (login icazəsi)</label>
            </div>

            <div class="pt-4 border-t border-border flex items-center justify-between">
                <Link :href="route('merchant.staff')" class="text-sm text-muted hover:text-accent">
                    Ləğv et
                </Link>
                <button type="submit" :disabled="form.processing" class="btn-primary px-6">
                    {{ form.processing ? 'Yadda saxlanır...' : (isEdit ? 'Yenilə' : 'Yarat') }}
                </button>
            </div>
        </form>
    </MerchantLayout>
</template>
