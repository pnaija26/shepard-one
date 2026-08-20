<template>
  <div class="space-y-2">
    <OrganizationNode
      v-for="org in organizations"
      :key="org.id"
      :organization="org"
      :depth="0"
      :on-edit="onEdit"
      :on-delete="onDelete"
    />
  </div>
</template>

<script setup>
import OrganizationNode from '@/components/OrganizationNode.vue'

defineProps({
  organizations: {
    type: Array,
    required: true
  },
  onEdit: {
    type: Function,
    default: null
  },
  onDelete: {
    type: Function,
    default: null
  }
})
</script>
