<script setup>
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: Boolean,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => form.post(route('login'), {
    onFinish: () => form.reset('password'),
});
</script>

<template>
    <Head title="Daxil ol" />

    <div class="min-h-screen grid lg:grid-cols-2 grain">
        <!-- Left: branding -->
        <aside class="hidden lg:flex flex-col justify-between p-12 bg-surface border-r border-border relative overflow-hidden">
            <!-- Background glow -->
            <div class="absolute inset-0 opacity-30 pointer-events-none"
                 style="background: radial-gradient(circle at 30% 30%, rgba(200,255,61,0.15), transparent 60%)"></div>

            <div class="relative z-10">
                <div class="flex items-center gap-3">
                    <span class="brand-dot" />
                    <span class="font-serif font-extrabold text-2xl tracking-tight">Paylo</span>
                </div>
            </div>

            <div class="relative z-10">
                <h1 class="font-serif font-light text-5xl leading-[0.95] tracking-tight">
                    Bir wallet,<br />
                    <em class="italic font-semibold text-accent">beş fərqli</em> həqiqət.
                </h1>
                <div class="mt-8 p-6 border-l-2 border-accent bg-surface/60 backdrop-blur">
                    <div class="font-mono text-[10px] uppercase tracking-widest text-accent mb-2">Əsas Prinsip</div>
                    <p class="text-sm leading-relaxed text-text-2">
                        Hər merchant-da qazanılan bonus yalnız o merchant-da xərclənir.
                        Vahid ledger, per-merchant bucket-lər, immutable audit.
                    </p>
                </div>
            </div>

            <div class="relative z-10 font-mono text-[10px] uppercase tracking-widest text-muted">
                v1.0 · Canonical Architecture
            </div>
        </aside>

        <!-- Right: form -->
        <section class="flex items-center justify-center p-8 md:p-16">
            <div class="w-full max-w-md">
                <div class="lg:hidden flex items-center gap-3 mb-12">
                    <span class="brand-dot" />
                    <span class="font-serif font-extrabold text-xl">Paylo</span>
                </div>

                <div class="font-mono text-[10px] uppercase tracking-widest text-muted">01 / Authentication</div>
                <h2 class="font-serif text-4xl font-light mt-3 tracking-tight">
                    Sistemə <em class="italic font-semibold text-accent">daxil</em> olun
                </h2>
                <p class="mt-3 text-sm text-muted leading-relaxed">
                    Hesabınızın roluna görə avtomatik müvafiq panelə yönləndiriləcəksiniz.
                </p>

                <form @submit.prevent="submit" class="mt-10 space-y-5">
                    <div>
                        <label class="font-mono text-[10px] uppercase tracking-widest text-muted mb-2 block">E-poçt</label>
                        <input
                            v-model="form.email"
                            type="email"
                            autofocus
                            autocomplete="username"
                            required
                            class="input"
                            placeholder="ad@paylo.az"
                        />
                        <div v-if="form.errors.email" class="mt-2 text-xs text-danger font-mono">{{ form.errors.email }}</div>
                    </div>

                    <div>
                        <label class="font-mono text-[10px] uppercase tracking-widest text-muted mb-2 block">Şifrə</label>
                        <input
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="input"
                            placeholder="••••••••"
                        />
                        <div v-if="form.errors.password" class="mt-2 text-xs text-danger font-mono">{{ form.errors.password }}</div>
                    </div>

                    <label class="flex items-center gap-3 cursor-pointer select-none">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            class="w-4 h-4 bg-surface border-border text-accent rounded-none focus:ring-accent focus:ring-1"
                        />
                        <span class="font-mono text-[11px] uppercase tracking-wider text-muted">Məni xatırla</span>
                    </label>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full bg-accent text-bg font-mono text-xs uppercase tracking-widest py-3.5 hover:brightness-110 transition disabled:opacity-50"
                    >
                        <span v-if="!form.processing">Daxil ol →</span>
                        <span v-else>Yoxlanılır...</span>
                    </button>
                </form>
            </div>
        </section>
    </div>
</template>
