<template>
  <div class="space-y-2">
    <OrganizationNode 
      v-for="org in organizations" 
      :key="org.id" 
      :organization="org"
      :depth="0"
    />
  </div>
</template>

<script setup>
import { defineProps } from 'vue'
import OrganizationNode from '@/components/OrganizationNode.vue'

defineProps({
  organizations: {
    type: Array,
    required: true
  }
})
</script>