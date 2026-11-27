<template>
  <UiPageHeader
    title="Standorte"
    description="Adresse, Gebäude (optional), Raum, Lagerplatz und Fach. Die Kennziffer setzt sich aus den Kürzeln zusammen; ein Gebäude nur, wenn es „im Code“ steht."
  >
    <UiButton
      v-if="can('locations.manage')"
      icon="plus"
      @click="openCreate(null)"
    >
      Adresse anlegen
    </UiButton>
  </UiPageHeader>

  <UiCard flush>
    <div
      v-if="locations.length"
      class="flex flex-wrap items-center gap-4 border-b border-line p-4 sm:px-6"
    >
      <UiSelect
        v-model="labelFilter"
        label="Etikett"
        :options="labelOptions"
        class="w-full sm:w-56"
      />
      <UiButton
        v-if="
          can('labels.print') && labelFilter === 'missing' && unlabeled.length
        "
        variant="secondary"
        size="sm"
        icon="tag"
        class="ml-auto"
        :href="`/labels/print?locations=${unlabeled.map((location) => location.id).join(',')}`"
      >
        {{ unlabeled.length }} Etiketten drucken
      </UiButton>
    </div>
    <UiEmptyState
      v-if="!locations.length"
      icon="location-dot"
      title="Noch keine Standorte"
      description="Lege zuerst eine Adresse an, darin Räume, Lagerplätze und Fächer."
    />
    <ul v-else class="divide-y divide-line">
      <li
        v-for="location in visible"
        :key="location.id"
        class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2 sm:px-6"
      >
        <div
          class="flex min-w-0 flex-1 items-center gap-2"
          :style="{ paddingLeft: `${depth(location) * 1.25}rem` }"
        >
          <button
            v-if="hasChildren(location)"
            type="button"
            class="w-4 text-ink-muted hover:text-ink"
            :aria-label="
              collapsed.has(location.id) ? 'Aufklappen' : 'Zuklappen'
            "
            @click="toggle(location.id)"
          >
            <FontAwesomeIcon
              :icon="[
                'fas',
                collapsed.has(location.id) ? 'chevron-right' : 'chevron-down',
              ]"
            />
          </button>
          <span v-else class="w-4" />
          <FontAwesomeIcon
            v-if="location.type === 'building'"
            :icon="['fas', 'building']"
            class="text-ink-muted"
          />
          <UiLink
            :href="`/inventory/locations/${location.id}`"
            class="font-mono font-semibold"
          >
            {{ location.full_code }}
          </UiLink>
          <span class="truncate">{{ location.name }}</span>
          <span class="text-xs text-ink-muted">{{ location.type_label }}</span>
          <UiBadge v-if="location.items_count">{{
            location.items_count
          }}</UiBadge>
          <span v-if="location.no_label" class="text-xs text-ink-muted"
            >· kein Etikett nötig</span
          >
        </div>
        <div v-if="can('locations.manage')" class="flex gap-2">
          <UiButton
            v-for="child in childTypes[location.type]"
            :key="child"
            variant="ghost"
            size="sm"
            icon="plus"
            @click="openCreate(location, child)"
          >
            {{ typeLabels[child] }}
          </UiButton>
          <UiButton
            variant="ghost"
            size="sm"
            icon="pen"
            @click="openEdit(location)"
          >
            <span class="sr-only">Bearbeiten</span>
          </UiButton>
        </div>
      </li>
    </ul>
  </UiCard>

  <InventoryLocationModal
    v-if="editing"
    :location="editing.location"
    :parent-id="editing.parentId"
    :type="editing.type"
    :locations="locations"
    @close="editing = null"
  />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";

type LocationRow = {
  id: number;
  parent_id: number | null;
  type: LocationType;
  type_label: string;
  code: string;
  name: string | null;
  full_code: string;
  show_in_code: boolean;
  description: string | null;
  items_count: number;
  label_printed_at: string | null;
  no_label: boolean;
};

/**
 * Tree of all locations as a list with indentation: every location after its parent, siblings by
 * full code. The order is built here, because a building that is not in the code does not sort
 * its rooms below itself by the full code.
 */
const props = defineProps<{ locations: LocationRow[] }>();
const { can } = usePermissions();

const typeLabels: Record<LocationType, string> = {
  address: "Adresse",
  building: "Gebäude",
  room: "Raum",
  shelf: "Lagerplatz",
  compartment: "Fach",
};
// levels a child can have (App\Enums\LocationType::childTypes)
const childTypes: Record<LocationType, LocationType[]> = {
  address: ["room", "building"],
  building: ["room"],
  room: ["shelf"],
  shelf: ["compartment"],
  compartment: [],
};

// full codes as people count: R01.2 before R01.10 (App\Models\Location::naturally)
const byCode = (a: string, b: string) =>
  a.localeCompare(b, "de", { numeric: true });

const tree = computed(() => {
  const children = new Map<number | null, LocationRow[]>();
  for (const location of props.locations) {
    children.set(location.parent_id, [
      ...(children.get(location.parent_id) ?? []),
      location,
    ]);
  }
  const rows: { location: LocationRow; depth: number }[] = [];
  const walk = (parentId: number | null, depth: number) => {
    const siblings = [...(children.get(parentId) ?? [])].sort((a, b) =>
      byCode(a.full_code, b.full_code),
    );
    for (const location of siblings) {
      rows.push({ location, depth });
      walk(location.id, depth + 1);
    }
  };
  walk(null, 0);
  return rows;
});
const depths = computed(
  () => new Map(tree.value.map((row) => [row.location.id, row.depth])),
);
const depth = (location: LocationRow) => depths.value.get(location.id) ?? 0;

const parents = computed(
  () => new Set(props.locations.map((location) => location.parent_id)),
);
const hasChildren = (location: LocationRow) => parents.value.has(location.id);

// collapsed locations hide everything below them
const collapsed = ref(new Set<number>());
const toggle = (id: number) => {
  const next = new Set(collapsed.value);
  if (!next.delete(id)) {
    next.add(id);
  }
  collapsed.value = next;
};
const byId = computed(
  () => new Map(props.locations.map((location) => [location.id, location])),
);
const isHidden = (location: LocationRow): boolean => {
  const parent = location.parent_id
    ? byId.value.get(location.parent_id)
    : undefined;
  return !!parent && (collapsed.value.has(parent.id) || isHidden(parent));
};
// filter "Etikett" (App\Enums\LabelStatus): addresses get no label, so they are left out
const labelOptions: Option[] = [
  { value: "missing", label: "Etikett fehlt" },
  { value: "printed", label: "Etikett gedruckt" },
  { value: "none", label: "Bewusst ohne Etikett" },
];
const labelFilter = ref<string | null>(null);
const labelStatus = (location: LocationRow) =>
  location.no_label
    ? "none"
    : location.label_printed_at
      ? "printed"
      : "missing";
const unlabeled = computed(() =>
  props.locations.filter(
    (location) =>
      location.type !== "address" && labelStatus(location) === "missing",
  ),
);
const visible = computed(() =>
  labelFilter.value
    ? props.locations
        .filter(
          (location) =>
            location.type !== "address" &&
            labelStatus(location) === labelFilter.value,
        )
        .sort((a, b) => byCode(a.full_code, b.full_code))
    : tree.value
        .map((row) => row.location)
        .filter((location) => !isHidden(location)),
);

const editing = ref<{
  location: LocationRow | null;
  parentId: number | null;
  type: LocationType | null;
} | null>(null);
const openCreate = (
  parent: LocationRow | null,
  type: LocationType | null = null,
) => (editing.value = { location: null, parentId: parent?.id ?? null, type });
const openEdit = (location: LocationRow) =>
  (editing.value = { location, parentId: location.parent_id, type: null });
</script>
