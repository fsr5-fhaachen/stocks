<template>
  <ul
    :class="nested ? 'ml-5 border-l border-line pl-3' : 'divide-y divide-line'"
  >
    <li
      v-for="item in items"
      :key="item.key ?? item.id"
      :class="nested ? 'pt-2' : 'py-2'"
    >
      <div class="flex items-center gap-2">
        <button
          v-if="item.contents.length"
          type="button"
          class="w-4 text-ink-muted hover:text-ink"
          :aria-label="open.has(item.id) ? 'Zuklappen' : 'Aufklappen'"
          @click="toggle(item.id)"
        >
          <FontAwesomeIcon
            :icon="[
              'fas',
              open.has(item.id) ? 'chevron-down' : 'chevron-right',
            ]"
          />
        </button>
        <span v-else class="w-4" />
        <img
          v-if="item.photo_url"
          :src="item.photo_url"
          alt=""
          class="h-8 w-8 shrink-0 rounded object-cover"
          loading="lazy"
        />
        <div
          v-else
          class="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-surface-muted text-sm text-ink-muted"
        >
          <FontAwesomeIcon
            :icon="['fas', item.is_container ? 'box' : 'boxes-stacked']"
          />
        </div>
        <!-- the kiosk has no item pages -->
        <span v-if="kiosk">{{ item.name }}</span>
        <UiLink v-else :href="`/inventory/items/${item.id}`">{{
          item.name
        }}</UiLink>
        <span class="font-mono text-xs text-ink-muted">{{ item.number }}</span>
        <!-- bulk pieces: the quantity in this box, with its target ("Sollmenge") -->
        <UiBadge
          v-if="item.kind === 'bulk'"
          :variant="item.stock?.short ? 'danger' : 'neutral'"
          >{{ item.quantity
          }}<template v-if="item.stock?.target_quantity">
            / {{ item.stock.target_quantity }}</template
          >
          {{ item.unit }}</UiBadge
        >
        <!-- right in the list: "Verbraucht" (consumables) and "Ausmustern", from this box -->
        <span v-if="actions" class="ml-auto flex shrink-0 gap-1">
          <!-- bulk pieces: "+" out of "Eingang", their target ("Sollmenge") -->
          <UiButton
            v-if="item.stock && can('inventory.move')"
            size="sm"
            variant="secondary"
            icon="plus"
            :aria-label="`${item.name} aus dem Eingang dazutun`"
            @click="$emit('add', item)"
          />
          <UiButton
            v-if="item.stock && can('inventory.edit')"
            size="sm"
            variant="secondary"
            icon="bullseye"
            :aria-label="`${item.name}: Sollmenge`"
            @click="$emit('target', item)"
          />
          <UiButton
            v-if="item.consumable && item.quantity > 0 && can('inventory.move')"
            size="sm"
            variant="secondary"
            icon="minus"
            :aria-label="`${item.name} verbraucht`"
            @click="$emit('consume', item)"
          />
          <UiButton
            v-if="
              can('inventory.retire') &&
              (item.kind !== 'bulk' || item.quantity > 0)
            "
            size="sm"
            variant="secondary"
            icon="box-archive"
            :aria-label="`${item.name} ausmustern`"
            @click="$emit('retire', item)"
          />
        </span>
      </div>
      <InventoryContentsTree
        v-if="item.contents.length && open.has(item.id)"
        :items="item.contents"
        :actions="actions"
        nested
        @consume="$emit('consume', $event)"
        @retire="$emit('retire', $event)"
        @add="$emit('add', $event)"
        @target="$emit('target', $event)"
      />
    </li>
  </ul>
</template>

<script setup lang="ts">
import { ref, type PropType } from "vue";

/**
 * Contents of a box as tree; boxes in boxes can be opened.
 */
// the name lets the component use itself for boxes in boxes; the automatic import does not cover that
defineOptions({ name: "InventoryContentsTree" });
defineProps({
  items: { type: Array as PropType<ContentsItem[]>, required: true },
  nested: { type: Boolean, default: false },
  // buttons "Verbraucht" and "Ausmustern" at each entry
  actions: { type: Boolean, default: false },
});
defineEmits<{
  consume: [item: ContentsItem];
  retire: [item: ContentsItem];
  add: [item: ContentsItem];
  target: [item: ContentsItem];
}>();
const { can } = usePermissions();

const { kiosk } = useSurface();

const open = ref(new Set<number>());
const toggle = (id: number) => {
  const next = new Set(open.value);
  if (!next.delete(id)) {
    next.add(id);
  }
  open.value = next;
};
</script>
