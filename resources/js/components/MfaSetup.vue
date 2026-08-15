<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900 p-4">
    <div class="w-full max-w-md">
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-8 space-y-6">
        <div class="text-center">
          <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Multi-Factor Authentication</h1>
          <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Set up MFA for your account
          </p>
        </div>

        <div v-if="errors.length > 0" class="rounded-md bg-danger-soft p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-danger" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.75 9.25a.75.75 0 000 1.5h2.5a.75.75 0 000-1.5h-2.5z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="ml-3">
              <p class="text-sm text-danger-ink">{{ errors[0] }}</p>
            </div>
          </div>
        </div>

        <form @submit.prevent="setupMfa" class="space-y-6">
          <div class="rounded-lg bg-info-soft p-4">
            <h3 class="text-sm font-medium text-info-ink">How MFA works</h3>
            <p class="mt-1 text-sm text-info-ink">
              Multi-factor authentication adds an extra layer of security to your account. 
              You'll need both your password and a code from your authenticator app to sign in.
            </p>
          </div>

          <div>
            <label for="totp_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Authenticator Code
            </label>
            <input 
              id="totp_code" 
              type="text" 
              v-model="form.totp_code"
              required 
              autocomplete="one-time-code"
              placeholder="Enter 6-digit code from your authenticator app"
              class="w-full rounded-lg border border-line px-4 py-2 focus:border-brand focus:ring-2 focus:ring-brand"
            >
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Enter the 6-digit code shown in your authenticator app
            </p>
          </div>

          <div>
            <button type="submit" :disabled="loading" class="flex w-full justify-center rounded-md border border-transparent bg-brand px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2">
              <span v-if="loading">Setting up MFA...</span>
              <span v-else>Complete MFA Setup</span>
            </button>
          </div>
        </form>

        <div class="mt-4 text-center">
          <a href="/dashboard" class="text-sm text-brand hover:text-brand-hover">
            &larr; Back to Dashboard
          </a>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'MfaSetup',
  data() {
    return {
      form: {
        totp_code: ''
      },
      errors: [],
      loading: false
    }
  },
  methods: {
    async setupMfa() {
      this.errors = [];
      this.loading = true;
      
      try {
        // In a real implementation, we would make an API call to Laravel
        // For now, we'll simulate the MFA setup process
        
        // Simple validation
        if (!this.form.totp_code || this.form.totp_code.length !== 6) {
          this.errors.push('Please enter a valid 6-digit code');
          this.loading = false;
          return;
        }
        
        // Simulate API call delay
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        // For demo purposes, we'll just show a success message
        console.log('MFA setup submitted:', this.form);
        alert('MFA setup would be completed here. In a real app, it would communicate with Laravel backend.');
        
        // Redirect to dashboard after successful setup
        window.location.href = '/dashboard';
      } catch (error) {
        this.errors.push('An error occurred during MFA setup');
        console.error('MFA setup error:', error);
      } finally {
        this.loading = false;
      }
    }
  }
}
</script>