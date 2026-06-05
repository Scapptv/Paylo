<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    mode:     { type: String, required: true }, // 'create' | 'edit'
    merchant: { type: Object, default: null },
});

const isEdit = props.mode === 'edit';

const form = useForm({
    code:             props.merchant?.code             || '',
    name:             props.merchant?.name             || '',
    legal_name:       props.merchant?.legal_name       || '',
    tin:              props.merchant?.tin              || '',
    mcc:              props.merchant?.mcc              || 5411,
    category:         props.merchant?.category         || 'grocery',
    tier:             props.merchant?.tier             || 'standard',
    status:           props.merchant?.status           || 'pending',
    region:           props.merchant?.region           || '',
    settlement_iban:  props.merchant?.settlement_iban  || '',
    settlement_cycle: props.merchant?.settlement_cycle || 'T+1',
});

function submit() {
    if (isEdit) {
        form
            .transform(({ code, tin, ...rest }) => rest) // immutable sahələri server-ə göndərmə
            .put(route('admin.merchants.update', props.merchant.id));
    } else {
        form.post(route('admin.merchants.store'));
    }
}
</script>

<template>
    <Head :title="isEdit ? `Edit ${merchant.name}` : 'New Merchant'" />
    <AdminLayout :breadcrumb="isEdit ? 'Merchant Edit' : 'Merchant Yarat'">

        <Link :href="route('admin.merchants')" class="font-mono text-xs uppercase tracking-wider text-muted hover:text-accent mb-6 inline-block">
            ← Bütün merchant-lar
        </Link>

        <div class="mb-8">
            <div class="section-title">{{ isEdit ? 'Mövcud merchant' : 'Yeni merchant' }}</div>
            <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                {{ isEdit ? merchant.name : 'Yeni' }}
                <em class="italic font-semibold text-accent">{{ isEdit ? 'redaktə' : 'merchant qeydiyyatı' }}</em>
            </h1>
        </div>

        <form @submit.prevent="submit" class="card max-w-3xl space-y-6">

            <!-- Identifier-lər: yaradılışda dəyişdirilir, redaktə-də readonly -->
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Code</label>
                    <input v-model="form.code" type="text" class="input"
                           :readonly="isEdit" :class="{ 'opacity-60 cursor-not-allowed': isEdit }"
                           placeholder="m_412" />
                    <div v-if="form.errors.code" class="text-xs text-danger mt-1">{{ form.errors.code }}</div>
                    <div v-if="isEdit" class="text-[11px] text-muted mt-1">Code immutable — dəyişdirilə bilməz</div>
                </div>
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">TIN</label>
                    <input v-model="form.tin" type="text" class="input" maxlength="10"
                           :readonly="isEdit" :class="{ 'opacity-60 cursor-not-allowed': isEdit }"
                           placeholder="1700412091" />
                    <div v-if="form.errors.tin" class="text-xs text-danger mt-1">{{ form.errors.tin }}</div>
                    <div v-if="isEdit" class="text-[11px] text-muted mt-1">TIN immutable</div>
                </div>
            </div>

            <!-- Adlar -->
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Brand Name</label>
                    <input v-model="form.name" type="text" class="input" placeholder="Bravo Market" />
                    <div v-if="form.errors.name" class="text-xs text-danger mt-1">{{ form.errors.name }}</div>
                </div>
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Legal Name</label>
                    <input v-model="form.legal_name" type="text" class="input" placeholder="Bravo Supermarket MMC" />
                    <div v-if="form.errors.legal_name" class="text-xs text-danger mt-1">{{ form.errors.legal_name }}</div>
                </div>
            </div>

            <!-- Classification -->
            <div class="grid md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Category</label>
                    <select v-model="form.category" class="input">
                        <option value="grocery">grocery</option>
                        <option value="restaurant">restaurant</option>
                        <option value="fuel">fuel</option>
                        <option value="pharmacy">pharmacy</option>
                        <option value="retail">retail</option>
                    </select>
                    <div v-if="form.errors.category" class="text-xs text-danger mt-1">{{ form.errors.category }}</div>
                </div>
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Tier</label>
                    <select v-model="form.tier" class="input">
                        <option value="standard">standard (1.00x)</option>
                        <option value="premium">premium (1.25x)</option>
                        <option value="enterprise">enterprise (1.50x)</option>
                    </select>
                    <div v-if="form.errors.tier" class="text-xs text-danger mt-1">{{ form.errors.tier }}</div>
                </div>
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">MCC</label>
                    <input v-model.number="form.mcc" type="number" class="input" min="1000" max="9999" />
                    <div v-if="form.errors.mcc" class="text-xs text-danger mt-1">{{ form.errors.mcc }}</div>
                </div>
            </div>

            <!-- Status + region -->
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Status</label>
                    <select v-model="form.status" class="input">
                        <option value="pending">pending (onboarding)</option>
                        <option value="active">active (canlı)</option>
                        <option value="paused">paused (dondurulub)</option>
                        <option value="revoked">revoked (ləğv)</option>
                    </select>
                    <div v-if="form.errors.status" class="text-xs text-danger mt-1">{{ form.errors.status }}</div>
                </div>
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Region</label>
                    <input v-model="form.region" type="text" class="input" placeholder="Bakı" />
                    <div v-if="form.errors.region" class="text-xs text-danger mt-1">{{ form.errors.region }}</div>
                </div>
            </div>

            <!-- Settlement -->
            <div class="grid md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Settlement IBAN</label>
                    <input v-model="form.settlement_iban" type="text" class="input" placeholder="AZ21NABZ00000000137010001944" />
                    <div v-if="form.errors.settlement_iban" class="text-xs text-danger mt-1">{{ form.errors.settlement_iban }}</div>
                </div>
                <div>
                    <label class="block text-xs font-mono uppercase tracking-wider text-muted mb-1.5">Cycle</label>
                    <select v-model="form.settlement_cycle" class="input">
                        <option value="T+1">T+1</option>
                        <option value="T+3">T+3</option>
                        <option value="T+5">T+5</option>
                    </select>
                </div>
            </div>

            <!-- Submit -->
            <div class="pt-4 border-t border-border flex items-center justify-between">
                <Link :href="route('admin.merchants')" class="text-sm text-muted hover:text-accent">
                    Ləğv et
                </Link>
                <button type="submit" :disabled="form.processing"
                        class="btn-primary px-6">
                    {{ form.processing ? 'Yadda saxlanır...' : (isEdit ? 'Yenilə' : 'Yarat') }}
                </button>
            </div>
        </form>

    </AdminLayout>
</template>
