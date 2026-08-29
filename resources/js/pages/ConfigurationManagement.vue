<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-6">Platform Configuration</h1>
    
    <!-- Category Tabs -->
    <div class="mb-6">
      <div class="flex space-x-2 border-b">
        <button
          v-for="category in categories"
          :key="category.name"
          @click="selectedCategory = category.name"
          :class="['px-4 py-2 font-medium', selectedCategory === category.name ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-500 hover:text-gray-700']"
        >
          {{ category.name }}
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center items-center py-12">
      <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
      <p class="text-red-700">{{ error }}</p>
    </div>

    <!-- Settings Form -->
    <div v-else class="bg-white rounded-lg shadow overflow-hidden">
      <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-medium text-gray-900">
          {{ selectedCategory || 'All Settings' }} Configuration
        </h2>
        <p class="mt-1 text-sm text-gray-500">
          Manage platform settings for {{ selectedCategory || 'all categories' }}
        </p>
      </div>

      <div class="divide-y divide-gray-200">
        <div v-for="setting in filteredSettings" :key="setting.key" class="px-6 py-4">
          <div class="flex items-start">
            <div class="flex-1">
              <label class="block text-sm font-medium text-gray-700 mb-1">
                {{ setting.key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) }}
              </label>
              <p class="text-sm text-gray-500 mb-2">{{ setting.description }}</p>
              
              <div v-if="setting.type === 'boolean'">
                <switch-input
                  :model-value="setting.value"
                  @update:model-value="updateSetting(setting.key, $event)"
                />
              </div>
              <div v-else-if="setting.type === 'integer'">
                <input
                  type="number"
                  :value="setting.value"
                  @input="updateSetting(setting.key, $event.target.value)"
                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                />
              </div>
              <div v-else-if="setting.type === 'json'">
                <textarea
                  :value="JSON.stringify(setting.value, null, 2)"
                  @input="updateSetting(setting.key, JSON.parse($event.target.value))"
                  rows="5"
                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm font-mono text-sm"
                />
              </div>
              <div v-else>
                <input
                  :type="setting.type === 'string' ? 'text' : setting.type"
                  :value="setting.value"
                  @input="updateSetting(setting.key, $event.target.value)"
                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="px-6 py-4 bg-gray-50 flex justify-end">
        <button
          @click="saveSettings"
          :disabled="saving"
          class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
        >
          {{ saving ? 'Saving...' : 'Save Settings' }}
        </button>
      </div>
    </div>

    <!-- Category Management -->
    <div class="mt-8 bg-white rounded-lg shadow overflow-hidden">
      <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-medium text-gray-900">Configuration Categories</h2>
        <p class="mt-1 text-sm text-gray-500">Manage categories for organizing settings</p>
      </div>

      <div class="px-6 py-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Category Name</label>
            <input
              v-model="newCategory.name"
              type="text"
              placeholder="Enter category name"
              class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Key Prefix</label>
            <input
              v-model="newCategory.keyPrefix"
              type="text"
              placeholder="Enter key prefix (optional)"
              class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
            />
          </div>
        </div>
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
          <textarea
            v-model="newCategory.description"
            rows="2"
            placeholder="Enter category description (optional)"
            class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
          />
        </div>
        <button
          @click="createCategory"
          class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
        >
          Create Category
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import Sidebar from '@/components/Sidebar.vue'
import apiClient from '@/api/client'

const authStore = useAuthStore()
const settings = ref([])
const categories = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref(null)
const selectedCategory = ref('')
const newCategory = ref({
  name: '',
  description: '',
  keyPrefix: ''
})

// Filter settings by selected category
const filteredSettings = computed(() => {
  if (!selectedCategory.value) return settings.value
  return settings.value.filter(setting => setting.category === selectedCategory.value)
})

const fetchSettings = async () => {
  loading.value = true
  error.value = null
  
  try {
    const response = await apiClient.get('/config')
    settings.value = response.data.data || []
    
    // Fetch categories
    const categoryResponse = await apiClient.get('/config/categories')
    categories.value = categoryResponse.data.data || []
  } catch (err) {
    error.value = 'Failed to load configuration settings'
    console.error('Error fetching settings:', err)
  } finally {
    loading.value = false
  }
}

const updateSetting = (key, value) => {
  const setting = settings.value.find(s => s.key === key)
  if (setting) {
    setting.value = value
  }
}

const saveSettings = async () => {
  saving.value = true
  error.value = null
  
  try {
    // In a real implementation, we would save individual settings
    // For now, this is just a placeholder for demonstration
    await new Promise(resolve => setTimeout(resolve, 1000))
    alert('Settings saved successfully!')
  } catch (err) {
    error.value = 'Failed to save settings'
    console.error('Error saving settings:', err)
  } finally {
    saving.value = false
  }
}

const createCategory = async () => {
  if (!newCategory.value.name.trim()) {
    alert('Please enter a category name')
    return
  }

  try {
    await apiClient.post('/config/categories', newCategory.value)
    // Refresh categories
    await fetchSettings()
    newCategory.value = { name: '', description: '', keyPrefix: '' }
    alert('Category created successfully!')
  } catch (err) {
    error.value = 'Failed to create category'
    console.error('Error creating category:', err)
  }
}

onMounted(() => {
  fetchSettings()
})
</script>