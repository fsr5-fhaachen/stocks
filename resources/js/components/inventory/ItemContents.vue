<template>
  <tr v-if="loading && !rows.length" :class="rowClass">
    <td :colspan="columns.length" :class="cellClass">
      <span class="text-ink-muted" :style="indent">Lade Inhalt …</span>
    </td>
  </tr>
  <template v-for="row in rows" :key="row.id">
    <tr :class="rowClass">
      <td v-for="column in columns" :key="column.name" :class="cellClass">
        <InventoryItemCell
          v-model:selected="selected"
          :row="row"
          :column="column.name"
          :depth="depth"
          expandable
          :open="open.has(row.id)"
          @toggle="toggle(row.id)"
        />
      </td>
    </tr>
    <InventoryItemContents
      v-if="open.has(row.id)"
      v-model:selected="selected"
      :box="row"
      :columns="columns"
      :depth="depth + 1"
    />
  </template>
  <tr v-if="more" :class="rowClass">
    <td :colspan="columns.length" :class="cellClass">
      <span :style="indent">
        … und {{ more }} weitere
        <UiLink :href="`/inventory/items/${box.id}`">in der Box</UiLink>
      </span>
    </td>
  </tr>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch, type PropType } from "vue";

/**
 * Contents of an opened box in the grouped item list: one row per item directly in the box, boxes
 * in it can be opened again. Loaded when the box is opened, again when the row of the box changes
 * (live update of the table); the rows themselves are not followed live.
 */
const props = defineProps({
  box: { type: Object as PropType<ItemRow>, required: true },
  columns: { type: Array as PropType<TableColumn[]>, required: true },
  // level below the list, 1 for the contents of a box of the list
  depth: { type: Number, default: 1 },
});

// ids of the items chosen for printing labels
const selected = defineModel<number[]>("selected", { required: true });

const rows = ref<ItemRow[]>([]);
const more = ref(0);
const loading = ref(false);

const load = async () => {
  loading.value = true;
  try {
    const response = await fetch(`/inventory/items/${props.box.id}/contents`, {
      headers: { Accept: "application/json" },
    });
    if (response.ok) {
      const data: { rows: ItemRow[]; more: number } = await response.json();
      rows.value = data.rows;
      more.value = data.more;
    }
  } finally {
    loading.value = false;
  }
};
onMounted(load);
watch(() => [props.box._version, props.box.contents_count], load);

// opened boxes of this level
const open = ref(new Set<number>());
const toggle = (id: number) => {
  const next = new Set(open.value);
  if (!next.delete(id)) {
    next.add(id);
  }
  open.value = next;
};

// same look as the rows of UiTable (stacked), on phones a line on the left instead of indenting
const rowClass =
  "block border-l-4 border-line bg-surface-muted/40 px-4 py-3 sm:table-row sm:border-l-0 sm:p-0";
const cellClass =
  "block py-0.5 sm:table-cell sm:whitespace-nowrap sm:px-6 sm:py-3";
const indent = computed(() => ({
  paddingLeft: `${props.depth * 1.75}rem`,
}));
</script>
