<template>
  <div class="border-l-2 border-gray-200 pl-4 py-1">
    <div class="flex items-center space-x-2">
      <div class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm font-medium">
        {{ organization.type }}
      </div>
      <div class="font-medium">{{ organization.name }}</div>
      <div class="text-gray-500 text-sm">({{ organization.identifier }})</div>
    </div>
    
    <!-- Display children recursively -->
    <div v-if="organization.children && organization.children.length > 0" class="mt-2">
      <OrganizationNode 
        v-for="child in organization.children" 
        :key="child.id" 
        :organization="child"
        :depth="depth + 1"
      />
    </div>
  </div>
</template>

<script setup>
import { defineProps } from 'vue'

defineProps({
  organization: {
    type: Object,
    required: true
  },
  depth: {
    type: Number,
    default: 0
  }
})
</script>