<template>
  <UiCard title="Inventar">
    <dl v-if="stats" class="grid grid-cols-2 gap-4">
      <div>
        <dt class="text-sm text-ink-muted">Gegenstände</dt>
        <dd class="text-2xl font-semibold">{{ stats.items }}</dd>
      </div>
      <div>
        <dt class="text-sm text-ink-muted">Ohne Platz</dt>
        <dd class="text-2xl font-semibold">
          <UiLink href="/inventory/misplaced">{{ stats.misplaced }}</UiLink>
        </dd>
      </div>
      <div v-if="stats.incoming.items" class="col-span-2">
        <dt class="text-sm text-ink-muted">Im Eingang</dt>
        <dd>
          <component
            :is="can('inventory.edit') ? UiLink : 'span'"
            href="/inventory/stock-in"
            >{{ stats.incoming.pieces }} Stück in
            {{ stats.incoming.items }}
            {{ stats.incoming.items === 1 ? "Artikel" : "Artikeln" }}</component
          >
        </dd>
      </div>
    </dl>
    <div v-else class="h-16 animate-pulse rounded bg-surface-muted" />
  </UiCard>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";
import UiLink from "../ui/Link.vue";

/**
 * Dashboard tile with the amount of items, how many are not at their place and what waits in
 * "Eingang".
 */
const stats = ref<{
  items: number;
  misplaced: number;
  // pieces in "Eingang", not put away yet
  incoming: { pieces: number; items: number };
} | null>(null);
const { can } = usePermissions();

onMounted(async () => {
  const response = await fetch("/inventory/stats", {
    headers: { Accept: "application/json" },
  });
  if (response.ok) {
    stats.value = await response.json();
  }
});
</script>
