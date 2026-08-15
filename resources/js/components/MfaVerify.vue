<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900 p-4">
    <div class="w-full max-w-md">
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-8 space-y-6">
        <div class="text-center">
          <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Multi-Factor Authentication</h1>
          <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
            Verify your identity with MFA
          </p>
        </div>

        <div v-if="errors.length > 0" class="rounded-md bg-red-50 dark:bg-red-900/20 p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.75 9.25a.75.75 0 000 1.5h2.5a.75.75 0 000-1.5h-2.5z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="ml-3">
              <p class="text-sm text-red-700 dark:text-red-300">{{ errors[0] }}</p>
            </div>
          </div>
        </div>

        <form @submit.prevent="verifyMfa" class="space-y-6">
          <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg">
            <h3 class="text-sm font-medium text-blue-800 dark:text-blue-200">Verify Your Identity</h3>
            <p class="mt-1 text-sm text-blue-700 dark:text-blue-300">
              Enter the 6-digit code from your authenticator app to verify your identity.
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
              class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
            >
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Enter the 6-digit code shown in your authenticator app
            </p>
          </div>

          <div>
            <button type="submit" :disabled="loading" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
              <span v-if="loading">Verifying...</span>
              <span v-else>Verify MFA</span>
            </button>
          </div>
        </form>

        <div class="mt-4 text-center">
          <a href="/dashboard" class="text-sm text-blue-600 hover:text-blue-500">
            &larr; Back to Dashboard
          </a>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'MfaVerify',
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
    async verifyMfa() {
      this.errors = [];
      this.loading = true;
      
      try {
        // In a real implementation, we would make an API call to Laravel
        // For now, we'll simulate the MFA verification process
        
        // Simple validation
        if (!this.form.totp_code || this.form.totp_code.length !== 6) {
          this.errors.push('Please enter a valid 6-digit code');
          this.loading = false;
          return;
        }
        
        // Simulate API call delay
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        // For demo purposes, we'll just show a success message
        console.log('MFA verification submitted:', this.form);
        alert('MFA verification would be completed here. In a real app, it would communicate with Laravel backend.');
        
        // Redirect to dashboard after successful verification
        window.location.href = '/dashboard';
      } catch (error) {
        this.errors.push('An error occurred during MFA verification');
        console.error('MFA verification error:', error);
      } finally {
        this.loading = false;
      }
    }
  }
}
</script>