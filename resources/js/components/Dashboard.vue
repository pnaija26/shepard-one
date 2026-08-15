<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Header -->
    <header class="bg-white dark:bg-gray-800 shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <div class="flex">
            <div class="flex-shrink-0 flex items-center">
              <h1 class="text-xl font-semibold text-gray-900 dark:text-white">ShepardOne</h1>
            </div>
            <nav class="-mx-2 -my-1 flex space-x-2">
              <a href="/dashboard" class="px-3 py-2 rounded-md text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white">Dashboard</a>
              <a href="#" class="px-3 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Profile</a>
            </nav>
          </div>
          <div class="flex items-center">
            <div class="ml-3 relative">
              <div class="flex items-center space-x-4">
                <span class="text-sm text-gray-700 dark:text-gray-300">User Name</span>
                <button @click="logout" class="text-sm text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white">Logout</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </header>

    <!-- Main Content -->
    <main class="py-6">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
          <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Dashboard</h2>
          
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="rounded-lg bg-info-soft p-6">
              <h3 class="text-lg font-medium text-info-ink">Welcome</h3>
              <p class="mt-2 text-info-ink">
                You are successfully logged in to the ShepardOne system.
              </p>
            </div>
            
            <div class="rounded-lg bg-success-soft p-6">
              <h3 class="text-lg font-medium text-success">Security Status</h3>
              <p class="mt-2 text-success">
                MFA is {{ userHasMfa ? 'enrolled' : 'not enrolled' }} for your account.
              </p>
            </div>
            
            <div class="rounded-lg bg-brand-soft p-6">
              <h3 class="text-lg font-medium text-brand">Role</h3>
              <p class="mt-2 text-brand">
                {{ userRole || 'No role assigned' }}
              </p>
            </div>
          </div>
          
          <div class="mt-8">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Quick Actions</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <button @click="setupMfa" 
                      v-if="!userHasMfa"
                      class="rounded-md bg-brand px-4 py-2 text-white hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand">
                Setup MFA
              </button>
              <button v-else 
                      class="px-4 py-2 bg-gray-600 text-white rounded-md cursor-not-allowed opacity-50" 
                      disabled>
                MFA Already Set Up
              </button>
              <button class="rounded-md bg-brand px-4 py-2 text-white hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand">
                View Profile
              </button>
              <button class="rounded-md bg-brand px-4 py-2 text-white hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand">
                Settings
              </button>
              <button class="rounded-md bg-accent px-4 py-2 text-ink hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-accent">
                Help Center
              </button>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<script>
export default {
  name: 'Dashboard',
  data() {
    return {
      userHasMfa: false,
      userRole: 'admin'
    }
  },
  methods: {
    logout() {
      // In a real app, this would make an API call to Laravel
      alert('In a real app, this would log the user out and redirect to login page.');
    },
    setupMfa() {
      // In a real app, this would navigate to MFA setup page
      alert('In a real app, this would navigate to the MFA setup page.');
    }
  }
}
</script>