<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import { debounce } from '@/Composables/useDebounce.js';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pill from '@/Components/Pill.vue';

const props = defineProps({
    users:   { type: Object, required: true },
    roles:   { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    authId:  { type: Number, required: true },
});

const page = usePage();

const state = reactive({
    role:   props.filters.role || '',
    active: props.filters.active ?? '',
    q:      props.filters.q || '',
});

const apply = debounce(() => {
    router.get(route('admin.users'), state, { preserveState: true, replace: true });
}, 250);
watch(state, apply, { deep: true });

const roleLabel = (v) => props.roles.find((r) => r.value === v)?.label || v;
const fmtDate = (s) => s ? new Date(s).toLocaleDateString('az-AZ', { dateStyle: 'medium' }) : '—';

// Aktivlik toggle təsdiqi (deaktivləşdirmə login-i bloklayır — təsdiq lazımdır).
const toggleTarget = ref(null);
const processing = ref(false);

function confirmToggle() {
    processing.value = true;
    router.post(route('admin.users.toggle-active', toggleTarget.value.id), {}, {
        preserveScroll: true,
        onFinish: () => { processing.value = false; toggleTarget.value = null; },
    });
}
</script>

<template>
    <Head title="Users" />
    <AdminLayout breadcrumb="Users">

        <div class="flex items-center justify-between mb-8">
            <div>
                <div class="section-title">User management</div>
                <h1 class="font-serif font-light text-4xl mt-1 tracking-tight">
                    <em class="italic font-semibold text-accent">İstifadəçilər</em>
                </h1>
            </div>
            <div class="font-mono text-xs text-muted"><span class="text-accent">{{ users.total }}</span> user</div>
        </div>

        <div v-if="page.props.flash?.success" class="card mb-4 border-l-2 border-l-success text-sm text-text">✓ {{ page.props.flash.success }}</div>
        <div v-if="page.props.flash?.error" class="card mb-4 border-l-2 border-l-danger text-sm text-danger">⚠ {{ page.props.flash.error }}</div>

        <div class="card mb-6 flex flex-wrap gap-3">
            <input v-model="state.q" type="search" placeholder="ad / email..." class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs flex-1 min-w-[220px] focus:outline-none focus:border-accent" />
            <select v-model="state.role" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option value="">All roles</option>
                <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
            <select v-model="state.active" class="bg-surface-2 border border-border px-3 py-2 font-mono text-xs focus:outline-none focus:border-accent">
                <option value="">All status</option>
                <option value="1">Aktiv</option>
                <option value="0">Deaktiv</option>
            </select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="font-mono text-[10px] uppercase tracking-wider text-muted border-b border-border">
                        <th class="text-left py-2 px-2">İstifadəçi</th>
                        <th class="text-left py-2 px-2">Rol</th>
                        <th class="text-left py-2 px-2">Merchant</th>
                        <th class="text-left py-2 px-2">Status</th>
                        <th class="text-left py-2 px-2">Qeydiyyat</th>
                        <th class="text-right py-2 px-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users.data" :key="u.id" class="border-b border-border/50 hover:bg-surface-2">
                        <td class="py-2 px-2">
                            <div>{{ u.name || '—' }}</div>
                            <div class="font-mono text-[10px] text-muted">{{ u.email }}</div>
                        </td>
                        <td class="py-2 px-2 font-mono text-[11px]">{{ roleLabel(u.role) }}</td>
                        <td class="py-2 px-2 font-mono text-[11px] text-muted">
                            <span v-if="u.merchant">{{ u.merchant.code }} · {{ u.merchant.name }}</span>
                            <span v-else>—</span>
                        </td>
                        <td class="py-2 px-2">
                            <Pill :variant="u.is_active ? 'success' : 'danger'" dot>{{ u.is_active ? 'Aktiv' : 'Deaktiv' }}</Pill>
                        </td>
                        <td class="py-2 px-2 font-mono text-[11px] text-muted">{{ fmtDate(u.created_at) }}</td>
                        <td class="py-2 px-2 text-right">
                            <span v-if="u.id === authId" class="font-mono text-[10px] uppercase tracking-wider text-muted">Siz</span>
                            <button v-else @click="toggleTarget = u"
                                    class="font-mono text-[11px] uppercase tracking-wider hover:underline"
                                    :class="u.is_active ? 'text-danger' : 'text-success'">
                                {{ u.is_active ? 'Deaktiv et' : 'Aktiv et' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!users.data?.length">
                        <td colspan="6" class="py-16 text-center text-muted font-mono text-xs">İstifadəçi tapılmadı</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="users.last_page > 1" class="flex items-center justify-between mt-4 font-mono text-xs text-muted">
            <span>{{ users.from }}–{{ users.to }} / {{ users.total }}</span>
            <div class="flex gap-2">
                <button :disabled="!users.prev_page_url" @click="router.get(users.prev_page_url, {}, { preserveState: true })"
                        class="px-3 py-1 border border-border disabled:opacity-40 hover:border-accent">← Prev</button>
                <button :disabled="!users.next_page_url" @click="router.get(users.next_page_url, {}, { preserveState: true })"
                        class="px-3 py-1 border border-border disabled:opacity-40 hover:border-accent">Next →</button>
            </div>
        </div>

        <!-- Aktivlik toggle təsdiq modalı -->
        <div v-if="toggleTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="toggleTarget = null">
            <div class="card max-w-md w-full">
                <h2 class="font-serif text-xl font-semibold mb-1">
                    {{ toggleTarget.is_active ? 'İstifadəçini deaktiv et' : 'İstifadəçini aktiv et' }}
                </h2>
                <p class="text-xs text-muted mb-4 font-mono">{{ toggleTarget.name }} · {{ toggleTarget.email }}</p>
                <p v-if="toggleTarget.is_active" class="text-sm text-text mb-5">
                    Bu istifadəçinin girişi dərhal bağlanacaq və bütün aktiv sessiyaları (mobil/API) ləğv olunacaq.
                    Audit izi qorunur — bu silmə deyil.
                </p>
                <p v-else class="text-sm text-text mb-5">
                    Bu istifadəçi yenidən giriş edə biləcək.
                </p>
                <div class="flex items-center justify-between pt-3 border-t border-border">
                    <button type="button" @click="toggleTarget = null" class="text-sm text-muted hover:text-accent">Ləğv et</button>
                    <button type="button" @click="confirmToggle" :disabled="processing"
                            class="btn-primary px-5">
                        {{ processing ? 'İcra olunur...' : (toggleTarget.is_active ? 'Deaktiv et' : 'Aktiv et') }}
                    </button>
                </div>
            </div>
        </div>

    </AdminLayout>
</template>
