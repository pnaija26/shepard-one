<template>
  <div class="min-h-screen flex items-center justify-center bg-[--canvas] py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      <div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-[--ink]">
          Sign in to your account
        </h2>
        <p class="mt-2 text-center text-sm text-[--muted]">
          Enter your credentials to access the ShepardOne platform
        </p>
      </div>
      <form class="mt-8 space-y-6" @submit.prevent="handleLogin">
        <div class="space-y-4">
          <div>
            <label for="email" class="block text-sm font-medium text-[--ink] mb-1">
              Email address
            </label>
            <input
              id="email"
              v-model="form.email"
              name="email"
              type="email"
              autocomplete="email"
              required
              class="appearance-none rounded-lg relative block w-full px-3 py-2 border border-[--line] placeholder-[--muted] text-[--ink] rounded-md focus:outline-none focus:ring-2 focus:ring-[--forest] focus:border-[--forest] sm:text-sm"
              placeholder="Enter your email"
            >
          </div>
          <div>
            <label for="password" class="block text-sm font-medium text-[--ink] mb-1">
              Password
            </label>
            <input
              id="password"
              v-model="form.password"
              name="password"
              type="password"
              autocomplete="current-password"
              required
              class="appearance-none rounded-lg relative block w-full px-3 py-2 border border-[--line] placeholder-[--muted] text-[--ink] rounded-md focus:outline-none focus:ring-2 focus:ring-[--forest] focus:border-[--forest] sm:text-sm"
              placeholder="Enter your password"
            >
          </div>
        </div>

        <div class="flex items-center justify-between">
          <div class="flex items-center">
            <input
              id="remember-me"
              name="remember-me"
              type="checkbox"
              class="h-4 w-4 text-[--forest] focus:ring-[--forest] border-[--line] rounded"
            >
            <label for="remember-me" class="ml-2 block text-sm text-[--ink]">
              Remember me
            </label>
          </div>

          <div class="text-sm">
            <a href="#" class="font-medium text-[--forest] hover:text-[--green]">
              Forgot your password?
            </a>
          </div>
        </div>

        <div>
          <button
            type="submit"
            :disabled="loading"
            class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-lg text-white bg-[--forest] hover:bg-[--green] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[--forest] disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
          >
            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
              <svg
                v-if="loading"
                class="animate-spin -ml-1 mr-3 h-5 w-5 text-white"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  class="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  stroke-width="4"
                ></circle>
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
              </svg>
              <span v-else class="text-white">Sign in</span>
            </span>
          </button>
        </div>

        <div v-if="error" class="rounded-lg bg-red-50 p-4 border border-red-200">
          <div class="text-sm text-red-700">{{ error }}</div>
        </div>
      </form>
      
      <div class="mt-6 text-center text-xs text-[--muted]">
        <p>ShepardOne Church Management System</p>
        <p class="mt-1">© 2026 ShepardOne. All rights reserved.</p>
      </div>
    </div>
  </div>
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