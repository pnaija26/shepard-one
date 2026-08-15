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
            <div class="bg-blue-50 dark:bg-blue-900/20 p-6 rounded-lg">
              <h3 class="text-lg font-medium text-blue-800 dark:text-blue-200">Welcome</h3>
              <p class="mt-2 text-blue-700 dark:text-blue-300">
                You are successfully logged in to the ShepardOne system.
              </p>
            </div>
            
            <div class="bg-green-50 dark:bg-green-900/20 p-6 rounded-lg">
              <h3 class="text-lg font-medium text-green-800 dark:text-green-200">Security Status</h3>
              <p class="mt-2 text-green-700 dark:text-green-300">
                MFA is {{ userHasMfa ? 'enrolled' : 'not enrolled' }} for your account.
              </p>
            </div>
            
            <div class="bg-purple-50 dark:bg-purple-900/20 p-6 rounded-lg">
              <h3 class="text-lg font-medium text-purple-800 dark:text-purple-200">Role</h3>
              <p class="mt-2 text-purple-700 dark:text-purple-300">
                {{ userRole || 'No role assigned' }}
              </p>
            </div>
          </div>
          
          <div class="mt-8">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Quick Actions</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <button @click="setupMfa" 
                      v-if="!userHasMfa"
                      class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                Setup MFA
              </button>
              <button v-else 
                      class="px-4 py-2 bg-gray-600 text-white rounded-md cursor-not-allowed opacity-50" 
                      disabled>
                MFA Already Set Up
              </button>
              <button class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                View Profile
              </button>
              <button class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500">
                Settings
              </button>
              <button class="px-4 py-2 bg-yellow-600 text-white rounded-md hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500">
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