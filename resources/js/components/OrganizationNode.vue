<template>
  <div class="border-l-2 border-gray-200 pl-4 py-1">
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
      <div class="rounded bg-blue-100 px-2 py-1 text-sm font-medium text-blue-800">
        {{ organization.type }}
      </div>
      <div class="font-medium">{{ organization.name }}</div>
      <div class="text-sm text-gray-500">({{ organization.identifier }})</div>

      <div v-if="onEdit || onDelete" class="ml-auto flex items-center gap-1">
        <button
          v-if="onEdit"
          type="button"
          class="rounded-md border border-line px-2 py-1 text-xs font-medium text-brand hover:bg-canvas"
          aria-label="Edit organization"
          @click="handleEdit"
        >Edit</button>
        <button
          v-if="onDelete"
          type="button"
          class="rounded-md border border-line px-2 py-1 text-xs font-medium text-danger hover:bg-canvas"
          aria-label="Delete organization"
          @click="handleDelete"
        >Delete</button>
      </div>
    </div>

    <!-- Display children recursively -->
    <div v-if="organization.children && organization.children.length > 0" class="mt-2">
      <OrganizationNode
        v-for="child in organization.children"
        :key="child.id"
        :organization="child"
        :depth="depth + 1"
        :on-edit="onEdit"
        :on-delete="onDelete"
      />
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  organization: {
    type: Object,
    required: true
  },
  depth: {
    type: Number,
    default: 0
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

function handleEdit() {
  props.onEdit?.(props.organization)
}

function handleDelete() {
  // The page-level handler asks for confirmation before deleting.
  props.onDelete?.(props.organization)
}
</script>
