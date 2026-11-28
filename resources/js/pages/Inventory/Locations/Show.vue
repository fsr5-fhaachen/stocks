<template>
  <UiPageHeader
    :title="location.full_code"
    :description="
      [
        location.type_label,
        location.name,
        location.type === 'building'
          ? location.show_in_code
            ? 'steht im Code'
            : 'steht nicht im Code'
          : null,
      ]
        .filter(Boolean)
        .join(' · ')
    "
  >
    <UiButton
      v-if="
        can('inventory.edit') &&
        location.type !== 'address' &&
        location.type !== 'building'
      "
      icon="plus"
      :href="`/inventory/items/create?place=${encodeURIComponent(location.full_code)}`"
    >
      Gegenstand hier anlegen
    </UiButton>
    <UiButton
      v-if="canStock"
      variant="secondary"
      icon="box-open"
      @click="packOpen = true"
    >
      Mengenartikel einräumen
    </UiButton>
    <UiButton
      v-if="canStock && can('inventory.edit')"
      variant="secondary"
      icon="calculator"
      @click="targetOpen = true"
    >
      Sollmenge festlegen
    </UiButton>
    <UiButton
      v-if="canStock && stocks.some((entry) => entry.stock.short)"
      variant="secondary"
      icon="fill-drip"
      :disabled="filling"
      @click="fill"
    >
      Auffüllen
    </UiButton>
    <UiButton
      v-if="can('labels.print')"
      variant="secondary"
      icon="tag"
      :href="`/labels/print?locations=${location.id}`"
    >
      Etikett drucken
    </UiButton>
    <UiButton
      v-if="can('labels.print') && children.length"
      variant="secondary"
      icon="layer-group"
      :href="`/labels/print?below=${location.id}`"
    >
      Etiketten für alle Lagerplätze und Fächer darunter
    </UiButton>
    <UiButton
      v-if="can('locations.manage')"
      variant="secondary"
      icon="pen"
      @click="editOpen = true"
    >
      Bearbeiten
    </UiButton>
  </UiPageHeader>

  <nav
    v-if="path.length"
    class="-mt-4 mb-6 flex flex-wrap gap-2 text-sm"
    aria-label="Pfad"
  >
    <UiLink href="/inventory/locations" variant="muted">Standorte</UiLink>
    <template v-for="parent in path" :key="parent.id">
      <span class="text-ink-muted">›</span>
      <UiLink :href="`/inventory/locations/${parent.id}`" variant="muted">
        {{ parent.full_code }}
      </UiLink>
    </template>
  </nav>

  <p v-if="location.description" class="mb-6 whitespace-pre-line">
    {{ location.description }}
  </p>

  <div class="grid gap-6 lg:grid-cols-2">
    <UiCard v-if="location.type !== 'compartment'" title="Darin" flush>
      <ul v-if="children.length" class="divide-y divide-line">
        <li
          v-for="child in children"
          :key="child.id"
          class="flex items-center gap-2 px-4 py-3 sm:px-6"
        >
          <FontAwesomeIcon
            v-if="child.type === 'building'"
            :icon="['fas', 'building']"
            class="text-ink-muted"
          />
          <UiLink
            :href="`/inventory/locations/${child.id}`"
            class="font-mono font-semibold"
          >
            {{ child.full_code }}
          </UiLink>
          <span class="truncate">{{ child.name }}</span>
          <UiBadge v-if="child.items_count" class="ml-auto">{{
            child.items_count
          }}</UiBadge>
        </li>
      </ul>
      <UiEmptyState v-else icon="location-dot" title="Keine Standorte darin" />
    </UiCard>

    <UiCard title="Gegenstände hier" flush>
      <ul v-if="items.length || stocks.length" class="divide-y divide-line">
        <li
          v-for="item in items"
          :key="item.id"
          class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-6"
        >
          <FontAwesomeIcon
            v-if="item.is_container"
            :icon="['fas', 'box']"
            class="text-ink-muted"
          />
          <UiLink :href="`/inventory/items/${item.id}`">{{ item.name }}</UiLink>
          <span class="font-mono text-xs text-ink-muted">{{
            item.number
          }}</span>
          <UiBadge v-if="item.kind === 'bulk'"
            >{{ item.quantity }} {{ item.unit }}</UiBadge
          >
          <span v-if="item.is_container" class="text-xs text-ink-muted">
            {{ item.contents_count }} darin
          </span>
          <UiButton
            v-if="can('inventory.retire')"
            size="sm"
            variant="secondary"
            icon="box-archive"
            class="ml-auto"
            :aria-label="`${item.name} ausmustern`"
            @click="retiring = { ...item, from: null, there: null }"
          />
        </li>
        <!-- pieces of bulk items lying here, with their target ("Sollmenge") -->
        <li
          v-for="stock in stocks"
          :key="stock.stock.key + stock.id"
          class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-6"
        >
          <FontAwesomeIcon
            :icon="['fas', 'boxes-stacked']"
            class="text-ink-muted"
          />
          <UiLink :href="`/inventory/items/${stock.id}`">{{
            stock.name
          }}</UiLink>
          <span class="font-mono text-xs text-ink-muted">{{
            stock.number
          }}</span>
          <UiBadge :variant="stock.stock.short ? 'danger' : 'neutral'"
            >{{ stock.quantity
            }}<template v-if="stock.stock.target_quantity">
              / soll {{ stock.stock.target_quantity }}</template
            >
            {{ stock.unit }}</UiBadge
          >
          <!-- count here and set the target; "Verbraucht" (consumables), "Ausmustern" -->
          <span class="ml-auto flex gap-1">
            <UiButton
              v-if="can('inventory.edit')"
              size="sm"
              variant="secondary"
              icon="calculator"
              :aria-label="`${stock.name} zählen, Sollmenge`"
              @click="editing = stock"
            />
            <UiButton
              v-if="
                stock.consumable && can('inventory.move') && stock.quantity > 0
              "
              size="sm"
              variant="secondary"
              icon="minus"
              :aria-label="`${stock.name} verbraucht`"
              @click="consuming = stock"
            />
            <UiButton
              v-if="can('inventory.retire') && stock.quantity > 0"
              size="sm"
              variant="secondary"
              icon="box-archive"
              :aria-label="`${stock.name} ausmustern`"
              @click="
                retiring = {
                  ...stock,
                  kind: 'bulk',
                  from: stock.stock.key,
                  there: stock.quantity,
                }
              "
            />
          </span>
        </li>
      </ul>
      <UiEmptyState v-else icon="boxes-stacked" title="Hier liegt nichts" />
    </UiCard>
  </div>

  <InventoryStockTargetModal
    v-if="targetOpen"
    :place-code="location.full_code"
    @close="targetOpen = false"
    @done="targetOpen = false"
  />
  <InventoryStockEditModal
    v-if="editing"
    :item-id="editing.id"
    :unit="editing.unit"
    :stock="{
      id: 0,
      key: editing.stock.key,
      quantity: editing.quantity,
      target_quantity: editing.stock.target_quantity,
      short: editing.stock.short,
      place: { code: location.full_code } as Place,
    }"
    @close="editing = null"
    @done="editing = null"
  />
  <InventoryConsumeModal
    v-if="consuming"
    :item="consuming"
    :from="consuming.stock.key"
    @close="consuming = null"
    @done="consuming = null"
  />
  <InventoryRetireModal
    v-if="retiring"
    :item="retiring"
    :reasons="retireReasons"
    :from="retiring.from"
    :there="retiring.there"
    :place-name="`in ${location.full_code}`"
    @close="retiring = null"
    @done="retiring = null"
  />

  <InventoryPackModal
    v-if="packOpen"
    :target="location.full_code"
    title="Mengenartikel einräumen"
    @close="packOpen = false"
    @done="packOpen = false"
  />

  <InventoryLocationModal
    v-if="editOpen"
    :location="location"
    :parent-id="location.parent_id"
    :locations="locations"
    @close="editOpen = false"
  />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";

type LocationSummary = {
  id: number;
  parent_id: number | null;
  type: LocationType;
  type_label: string;
  code: string;
  name: string | null;
  full_code: string;
  show_in_code: boolean;
};

/**
 * A location with the locations and the items directly in it. Items in boxes are listed on the
 * page of the box.
 */
const props = defineProps<{
  location: LocationSummary & {
    description: string | null;
  };
  path: LocationSummary[];
  children: (LocationSummary & { items_count: number })[];
  items: {
    id: number;
    number: string;
    name: string;
    kind: string;
    quantity: number;
    unit: string | null;
    is_container: boolean;
    contents_count: number;
  }[];
  // pieces of bulk items lying here
  stocks: {
    id: number;
    number: string;
    name: string;
    unit: string | null;
    quantity: number;
    stock: StockMark;
    consumable: boolean;
  }[];
  retireReasons: Option[];
  // all locations, for moving this one
  locations: LocationSummary[];
}>();

const { can } = usePermissions();
const editOpen = ref(false);

// bulk pieces: "einräumen" from another place, "Auffüllen" up to the targets from "Eingang"
const canStock = computed(
  () =>
    can("inventory.move") &&
    props.location.type !== "address" &&
    props.location.type !== "building",
);
const packOpen = ref(false);
// "Verbraucht" and "Ausmustern" from this location
type Stock = (typeof props.stocks)[number];
const consuming = ref<Stock | null>(null);
const editing = ref<Stock | null>(null);
const targetOpen = ref(false);
const retiring = ref<{
  id: number;
  number: string;
  name: string;
  unit: string | null;
  kind: string;
  from: string | null;
  there: number | null;
} | null>(null);
const filling = ref(false);
const fill = () =>
  router.post(
    "/inventory/stocks/fill",
    { code: props.location.full_code },
    {
      preserveScroll: true,
      onStart: () => (filling.value = true),
      onFinish: () => (filling.value = false),
    },
  );
</script>
