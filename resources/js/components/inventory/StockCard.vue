<template>
  <!-- bulk item: its pieces per place ("Bestand"), "Eingang" first -->
  <UiCard title="Bestand">
    <template
      v-if="canAct && can('inventory.move') && quantityHere > 0"
      #actions
    >
      <UiButton
        size="sm"
        variant="secondary"
        icon="box-open"
        @click="$emit('pack')"
      >
        Einpacken
      </UiButton>
    </template>
    <p class="text-sm">
      <span class="font-semibold">{{ quantityHere }} {{ unit }} vorhanden</span>
      <span class="text-ink-muted"> · Gesamt {{ quantity }}</span>
      <span v-if="awayQuantity" class="text-ink-muted">
        · {{ awayQuantity }} unterwegs (verliehen, vermisst oder noch nicht
        eingeräumt)</span
      >
    </p>
    <!-- minimum stock of the whole item ("Mindestbestand"), lent pieces count -->
    <UiAlert
      v-if="minQuantity !== null && quantity < minQuantity"
      type="error"
      class="mt-3"
    >
      Unter Mindestbestand: {{ quantity }} von {{ minQuantity }} {{ unit }}.
    </UiAlert>
    <p v-else-if="minQuantity !== null" class="mt-1 text-sm text-ink-muted">
      Mindestbestand {{ minQuantity }} {{ unit }}
    </p>
    <ul v-if="stocks.length" class="mt-3 divide-y divide-line">
      <li
        v-for="stock in stocks"
        :key="stock.key"
        class="flex items-center justify-between gap-3 py-2.5"
      >
        <!-- place on one line, its count below: long codes do not break -->
        <div class="min-w-0 space-y-1">
          <p class="truncate whitespace-nowrap">
            <InventoryPlaceLink v-if="stock.place" :place="stock.place" />
            <span v-else class="font-semibold">Eingang</span>
          </p>
          <UiBadge :variant="stock.short ? 'danger' : 'neutral'">
            {{ stock.quantity
            }}<template v-if="stock.target_quantity">
              / soll {{ stock.target_quantity }}</template
            >
            {{ unit }}
          </UiBadge>
        </div>
        <span v-if="canAct" class="flex shrink-0 gap-1">
          <UiButton
            v-if="can('inventory.edit')"
            size="sm"
            variant="secondary"
            icon="calculator"
            :aria-label="`${stock.place?.code ?? 'Eingang'} zählen, Sollmenge`"
            @click="$emit('edit', stock)"
          />
          <UiButton
            v-if="can('inventory.move') && stock.quantity > 0"
            size="sm"
            variant="secondary"
            icon="right-left"
            :aria-label="`Von ${stock.place?.code ?? 'Eingang'} umlagern`"
            @click="$emit('move', stock.key)"
          />
          <UiButton
            v-if="consumable && can('inventory.move') && stock.quantity > 0"
            size="sm"
            variant="secondary"
            icon="minus"
            :aria-label="`Von ${stock.place?.code ?? 'Eingang'} verbraucht`"
            @click="$emit('consume', stock.key)"
          />
          <UiButton
            v-if="can('inventory.retire') && stock.quantity > 0"
            size="sm"
            variant="secondary"
            icon="box-archive"
            :aria-label="`Von ${stock.place?.code ?? 'Eingang'} ausmustern`"
            @click="$emit('retire', stock.key)"
          />
        </span>
      </li>
    </ul>
    <p v-else class="mt-3 text-sm text-ink-muted">
      Gerade liegt nichts an einem Platz.
    </p>
    <p v-if="home" class="mt-3 text-sm text-ink-muted">
      Stammplatz: <InventoryPlaceLink :place="home" />
    </p>
  </UiCard>
</template>

<script setup lang="ts">
/**
 * Stock of a bulk item per place with the actions of each place (zählen and Sollmenge, umlagern,
 * verbraucht, ausmustern) and "Einpacken"; the page opens its dialogs with the place as source
 * ("from").
 */
withDefaults(
  defineProps<{
    stocks: StockEntry[];
    quantity: number;
    quantityHere: number;
    awayQuantity: number;
    unit: string | null;
    home: Place | null;
    minQuantity?: number | null;
    consumable: boolean;
    // false for retired items and while a module locks the item
    canAct: boolean;
  }>(),
  { minQuantity: null },
);
defineEmits<{
  move: [key: string];
  consume: [key: string];
  retire: [key: string];
  pack: [];
  edit: [stock: StockEntry];
}>();

const { can } = usePermissions();
</script>
