<template>
  <!-- name with selection, photo and, for a box in the grouped list, the button to open it -->
  <div
    v-if="column === 'name'"
    class="flex items-center gap-3"
    :style="{ '--depth': depth }"
    :class="depth ? 'sm:pl-[calc(var(--depth)*1.75rem)]' : ''"
  >
    <button
      v-if="expandable && row.contents_count"
      type="button"
      class="-ml-1 w-5 shrink-0 text-ink-muted hover:text-ink"
      :aria-label="open ? 'Inhalt zuklappen' : 'Inhalt aufklappen'"
      :aria-expanded="open"
      @click="emit('toggle')"
    >
      <FontAwesomeIcon
        :icon="['fas', open ? 'chevron-down' : 'chevron-right']"
      />
    </button>
    <span v-else-if="expandable" class="-ml-1 w-5 shrink-0" />
    <!-- selection for printing several labels at once -->
    <input
      v-if="can('labels.print') && !row.retired"
      v-model="selected"
      type="checkbox"
      :value="row.id"
      class="h-4 w-4 shrink-0 rounded border-line text-accent focus:ring-accent"
      :aria-label="`${row.name} auswählen`"
    />
    <img
      v-if="row.photo_url"
      :src="row.photo_url"
      alt=""
      class="h-10 w-10 shrink-0 rounded object-cover"
      loading="lazy"
    />
    <div
      v-else
      class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-surface-muted text-ink-muted"
    >
      <FontAwesomeIcon
        :icon="['fas', row.is_container ? 'box' : 'boxes-stacked']"
      />
    </div>
    <div class="min-w-0">
      <UiLink :href="`/inventory/items/${row.id}`" class="font-medium">
        {{ row.name }}
      </UiLink>
      <p class="font-mono text-xs text-ink-muted">
        {{ row.number }}
        <UiBadge v-if="expandable && row.contents_count" class="ml-1">
          {{ row.contents_count }}
          {{ row.contents_count === 1 ? "Teil" : "Teile" }}
        </UiBadge>
      </p>
    </div>
  </div>

  <span v-else-if="column === 'quantity' && row.kind === 'bulk'">
    <!-- pieces away (lent, missing): vorhanden / gesamt -->
    <template
      v-if="row.quantity_here !== null && row.quantity_here < row.quantity"
      ><span :title="`${row.quantity_here} vorhanden von ${row.quantity}`"
        >{{ row.quantity_here }} / {{ row.quantity }}</span
      >
      {{ row.unit }}</template
    >
    <template v-else>{{ row.quantity }} {{ row.unit }}</template>
  </span>

  <template v-else-if="column === 'category'">{{ row.category }}</template>

  <template v-else-if="column === 'place'">
    <!-- bulk items lie at their places, not at one -->
    <span v-if="row.kind === 'bulk'" class="text-ink-muted">
      {{
        row.places_count === 1
          ? "an 1 Stelle"
          : `an ${row.places_count ?? 0} Stellen`
      }}
    </span>
    <InventoryPlaceLink v-else :place="row.place" empty="kein Ort" />
    <!-- below the minimum stock or a place below its target -->
    <UiBadge v-if="row.short" variant="danger" class="ml-2">Mangel</UiBadge>
    <UiBadge v-if="row.away_from_home" variant="warning" class="ml-2">
      nicht am Stammplatz
    </UiBadge>
    <UiBadge
      v-for="badge in row.badges"
      :key="badge.text"
      :variant="badge.variant === 'info' ? 'accent' : badge.variant"
      class="ml-2"
    >
      {{ badge.text }}
    </UiBadge>
  </template>

  <template v-else-if="column === 'condition'">
    <UiBadge :variant="row.condition_is_defect ? 'danger' : 'neutral'">
      {{ row.condition }}
    </UiBadge>
    <UiBadge v-if="row.retired" class="ml-2">ausgemustert</UiBadge>
  </template>
</template>

<script setup lang="ts">
import type { PropType } from "vue";

/**
 * A cell of the item list (pages/Inventory/Items/Index.vue), also used for the contents of an
 * opened box (InventoryItemContents), so both look the same.
 */
defineProps({
  row: { type: Object as PropType<ItemRow>, required: true },
  // name of the column: name, quantity, category, place, condition
  column: { type: String, required: true },
  // level below the list: 0 for rows of the list, 1 for the contents of a box …
  depth: { type: Number, default: 0 },
  // grouped list: a box can be opened
  expandable: { type: Boolean, default: false },
  open: { type: Boolean, default: false },
});

const emit = defineEmits<{ toggle: [] }>();

// ids of the items chosen for printing labels
const selected = defineModel<number[]>("selected", { required: true });

const { can } = usePermissions();
</script>
