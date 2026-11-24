<template>
  <UiPageHeader
    :title="misplaced ? 'Ohne Platz' : 'Gegenstände'"
    :description="
      misplaced
        ? 'Gegenstände ohne aktuellen Ort und solche, die nicht an ihrem Stammplatz liegen.'
        : undefined
    "
  >
    <UiButton
      v-if="can('labels.print') && selected.length"
      variant="secondary"
      icon="tag"
      :href="`/labels/print?items=${selected.join(',')}`"
    >
      {{ selected.length }} Etiketten drucken
    </UiButton>
    <UiButton
      v-if="can('inventory.edit')"
      href="/inventory/items/create"
      icon="plus"
    >
      Anlegen
    </UiButton>
  </UiPageHeader>

  <UiAlert v-if="found" type="info" class="mb-4">
    {{ found.type === "item" ? "Gegenstand" : "Standort" }}
    {{ found.code }} gefunden:
    <UiLink :href="found.href">{{ found.name ?? found.code }} öffnen</UiLink>
  </UiAlert>

  <UiCard flush>
    <div
      class="grid gap-4 border-b border-line p-4 sm:grid-cols-2 sm:px-6 lg:grid-cols-5"
    >
      <template v-if="!misplaced">
        <UiSelect
          v-model="filter.category"
          label="Kategorie"
          :options="categories"
          @update:model-value="applyFilters"
        />
        <UiSelect
          v-model="filter.condition"
          label="Zustand"
          :options="conditions"
          @update:model-value="applyFilters"
        />
        <UiCheckbox
          v-model="filter.retired"
          label="Ausgemusterte zeigen"
          class="lg:mt-7"
          @update:model-value="applyFilters"
        />
        <UiCheckbox
          v-model="filter.unlabeled"
          label="Noch ohne Etikett"
          class="lg:mt-7"
          @update:model-value="applyFilters"
        />
      </template>
      <UiCheckbox
        v-model="grouped"
        label="Nach Boxen gruppieren"
        :class="misplaced ? '' : 'lg:mt-7'"
        @update:model-value="applyGrouping"
      />
      <p
        v-if="grouped && !groupedActive"
        class="text-sm text-ink-muted sm:col-span-2 lg:col-span-5"
      >
        Gruppierung ist bei Suche und Filtern aus.
      </p>
    </div>

    <UiTable
      ref="table"
      data="items"
      :columns="columns"
      searchable
      search-placeholder="Name, Artikelnummer, Kategorie oder Kennziffer"
      stacked
      @search="lookup"
    >
      <template
        v-for="column in columns"
        :key="column.name"
        #[`cell-${column.name}`]="{ row }"
      >
        <InventoryItemCell
          v-model:selected="selected"
          :row="row"
          :column="column.name"
          :expandable="groupedActive"
          :open="open.has(row.id)"
          @toggle="toggle(row.id)"
        />
      </template>
      <template #after-row="{ row }">
        <InventoryItemContents
          v-if="groupedActive && open.has(row.id)"
          v-model:selected="selected"
          :box="row"
          :columns="columns"
        />
      </template>
    </UiTable>
  </UiCard>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { usePage } from "@inertiajs/vue3";

type Found = {
  type: "item" | "location";
  name: string | null;
  number?: string;
  full_code?: string;
  code: string;
  href: string;
};

/**
 * List of all items, or of the items without place ("Ohne Platz"). "Nach Boxen gruppieren" shows
 * only the items outside of boxes and the boxes, which open their contents; with search or
 * filters the list is flat. The choice is kept per device and list.
 */
const props = defineProps<{
  misplaced: boolean;
  categories: Option[];
  conditions: Option[];
  filters: {
    category?: string;
    condition?: string;
    retired?: string;
    unlabeled?: string;
    grouped?: string;
  };
}>();

const { can } = usePermissions();
const table = ref<{
  reload: (changes: Record<string, string | null>) => void;
} | null>(null);

const columns: TableColumn[] = [
  { name: "name", header: "Gegenstand", sortKey: "name" },
  { name: "quantity", header: "Menge" },
  { name: "category", header: "Kategorie" },
  { name: "place", header: "Ort" },
  { name: "condition", header: "Zustand", sortKey: "condition" },
];

const filter = ref({
  category: props.filters.category ? Number(props.filters.category) : null,
  condition: props.filters.condition ? Number(props.filters.condition) : null,
  retired: props.filters.retired === "1",
  unlabeled: props.filters.unlabeled === "1",
});

// items chosen for printing labels
const selected = ref<number[]>([]);

const applyFilters = () =>
  table.value?.reload({
    category: filter.value.category ? String(filter.value.category) : null,
    condition: filter.value.condition ? String(filter.value.condition) : null,
    retired: filter.value.retired ? "1" : null,
    unlabeled: filter.value.unlabeled ? "1" : null,
  });

// grouping by boxes, kept per device and list (localStorage can be missing or blocked)
const storageKey = props.misplaced
  ? "stocks.misplaced.grouped"
  : "stocks.items.grouped";
const grouped = ref(props.filters.grouped === "1");
const applyGrouping = () => {
  try {
    localStorage.setItem(storageKey, grouped.value ? "1" : "0");
  } catch {
    // not kept, still applied
  }
  open.value = new Set();
  table.value?.reload({ grouped: grouped.value ? "1" : null });
};
onMounted(() => {
  let stored = false;
  try {
    stored = localStorage.getItem(storageKey) === "1";
  } catch {
    // nothing kept
  }
  if (stored && !grouped.value) {
    grouped.value = true;
    applyGrouping();
  }
});
// the server groups only without search and filters (ItemsTable::isGrouped)
const page = usePage();
const groupedActive = computed(() => {
  const query = new URLSearchParams(page.url.split("?")[1] ?? "");
  return (
    grouped.value &&
    !query.get("search") &&
    !["category", "condition", "retired", "unlabeled"].some((key) =>
      query.get(key),
    )
  );
});

// opened boxes of the list
const open = ref(new Set<number>());
const toggle = (id: number) => {
  const next = new Set(open.value);
  if (!next.delete(id)) {
    next.add(id);
  }
  open.value = next;
};

// a search that is exactly an item number or a location code offers to open it
const found = ref<Found | null>(null);
const lookup = async (search: string) => {
  found.value = null;
  if (!search.trim()) {
    return;
  }

  const response = await fetch(
    `/inventory/lookup?code=${encodeURIComponent(search)}`,
    {
      headers: { Accept: "application/json" },
    },
  );
  if (response.ok) {
    const data = await response.json();
    found.value = { ...data, code: data.number ?? data.full_code };
  }
};
</script>
