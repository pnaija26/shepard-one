<template>
  <main class="min-h-screen lg:grid lg:grid-cols-[minmax(320px,0.85fr)_minmax(520px,1.15fr)]">
    <section class="relative hidden overflow-hidden bg-brand px-10 py-12 text-white lg:flex lg:flex-col lg:justify-between" aria-labelledby="brand-heading">
      <div class="absolute inset-x-0 top-0 h-1 bg-accent"></div>
      <a href="/" class="relative inline-flex items-center gap-3 rounded-md text-white focus-visible:outline-white" aria-label="ShepardOne home">
        <span class="grid size-10 place-items-center rounded-md border border-white/20 bg-white/10 font-serif text-xl font-bold">S1</span>
        <span class="text-xl font-semibold">ShepardOne</span>
      </a>

      <div class="relative max-w-md">
        <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-accent">Church operations</p>
        <h1 id="brand-heading" class="font-serif text-4xl leading-tight">One calm place for the work that matters.</h1>
        <p class="mt-5 max-w-sm text-sm leading-6 text-white/75">Secure access for ministry teams, branch administrators, and members.</p>
        <dl class="mt-10 grid grid-cols-2 gap-x-8 gap-y-6 border-t border-white/15 pt-6 text-sm">
          <div><dt class="text-white/60">Workspace</dt><dd class="mt-1 font-medium text-white">Role-aware access</dd></div>
          <div><dt class="text-white/60">Data handling</dt><dd class="mt-1 font-medium text-white">Private by design</dd></div>
        </dl>
      </div>
      <p class="relative text-xs text-white/55">Authorized access only</p>
    </section>

    <section class="flex min-h-screen items-center justify-center bg-canvas px-5 py-10 sm:px-10">
      <div class="w-full max-w-[440px]">
        <a href="/" class="mb-10 inline-flex items-center gap-3 rounded-md text-brand lg:hidden" aria-label="ShepardOne home">
          <span class="grid size-10 place-items-center rounded-md bg-brand font-serif text-lg font-bold text-white">S1</span>
          <span class="text-xl font-semibold">ShepardOne</span>
        </a>

        <header class="mb-8">
          <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-brand">Secure workspace</p>
          <h2 class="font-serif text-3xl font-bold text-ink">Welcome back</h2>
          <p class="mt-2 text-sm leading-6 text-muted">Sign in with your ShepardOne account to continue.</p>
        </header>

        <div v-if="error" class="mb-6 rounded-md border border-danger/35 bg-danger-soft p-4" role="alert" aria-live="polite">
          <p class="text-sm font-semibold text-danger-ink">We could not sign you in</p>
          <p class="mt-1 text-sm text-danger-strong">{{ error }}</p>
        </div>

        <form class="space-y-5" @submit.prevent="handleLogin">
          <div>
            <label for="email" class="mb-2 block text-sm font-medium text-ink">Email address</label>
            <input id="email" v-model="form.email" name="email" type="email" required autofocus autocomplete="email" inputmode="email" class="min-h-12 w-full rounded-md border border-line bg-white px-4 text-sm text-ink shadow-sm placeholder:text-muted/70 hover:border-muted focus:border-brand focus:outline-none focus:ring-3 focus:ring-brand/20" placeholder="name@example.org">
            <p class="mt-2 text-xs text-muted">Use the email assigned to your organization.</p>
          </div>
          <div>
            <label for="password" class="mb-2 block text-sm font-medium text-ink">Password</label>
            <input id="password" v-model="form.password" name="password" type="password" required autocomplete="current-password" class="min-h-12 w-full rounded-md border border-line bg-white px-4 text-sm text-ink shadow-sm hover:border-muted focus:border-brand focus:outline-none focus:ring-3 focus:ring-brand/20">
          </div>
          <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm text-ink">
            <input type="checkbox" class="size-4 rounded border-line text-brand accent-brand focus:ring-brand">
            Keep me signed in on this device
          </label>
          <button type="submit" :disabled="loading" class="flex min-h-12 w-full items-center justify-center rounded-md bg-brand px-5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-hover focus-visible:outline-white focus-visible:ring-3 focus-visible:ring-brand/35 disabled:cursor-not-allowed disabled:opacity-60">
            {{ loading ? 'Signing in...' : 'Sign in' }}
          </button>
        </form>

        <div class="my-7 flex items-center gap-4" aria-hidden="true"><span class="h-px flex-1 bg-line"></span><span class="text-xs font-medium text-muted">OR</span><span class="h-px flex-1 bg-line"></span></div>
        <a href="/auth/redirect" class="flex min-h-12 w-full items-center justify-center rounded-md border border-line bg-white px-5 text-sm font-semibold text-ink shadow-sm transition-colors hover:border-muted hover:bg-surface-hover">Continue with identity provider</a>
        <p class="mt-8 border-t border-line pt-6 text-center text-sm text-muted">Need access or help signing in? <span class="font-medium text-ink">Contact your administrator.</span></p>
      </div>
    </section>
  </main>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const authStore = useAuthStore();

const form = ref({
  email: '',
  password: ''
});

const loading = ref(false);
const error = ref(null);

const handleLogin = async () => {
  loading.value = true;
  error.value = null;

  try {
    await authStore.login(form.value);
    
    // Redirect to dashboard
    router.push('/dashboard');
  } catch (err) {
    error.value = err.message || 'Invalid credentials';
  } finally {
    loading.value = false;
  }
};
</script>