<template>
  <!-- bulk item: its pieces per place ("Bestand"), "Eingang" first -->
  <UiCard title="Bestand">
    <p class="text-sm">
      <span class="font-semibold">{{ quantityHere }} {{ unit }} vorhanden</span>
      <span class="text-ink-muted"> · Gesamt {{ quantity }}</span>
      <span v-if="awayQuantity" class="text-ink-muted">
        · {{ awayQuantity }} unterwegs (verliehen, vermisst oder noch nicht
        eingeräumt)</span
      >
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
        <span v-if="canAct && stock.quantity > 0" class="flex shrink-0 gap-1">
          <UiButton
            v-if="can('inventory.move')"
            size="sm"
            variant="secondary"
            icon="right-left"
            :aria-label="`Von ${stock.place?.code ?? 'Eingang'} umlagern`"
            @click="$emit('move', stock.key)"
          />
          <UiButton
            v-if="consumable && can('inventory.move')"
            size="sm"
            variant="secondary"
            icon="minus"
            :aria-label="`Von ${stock.place?.code ?? 'Eingang'} verbraucht`"
            @click="$emit('consume', stock.key)"
          />
          <UiButton
            v-if="can('inventory.retire')"
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
 * Stock of a bulk item per place with the actions of each place (umlagern, verbraucht,
 * ausmustern); the page opens its dialogs with the place as source ("from").
 */
defineProps<{
  stocks: StockEntry[];
  quantity: number;
  quantityHere: number;
  awayQuantity: number;
  unit: string | null;
  home: Place | null;
  consumable: boolean;
  // false for retired items and while a module locks the item
  canAct: boolean;
}>();
defineEmits<{
  move: [key: string];
  consume: [key: string];
  retire: [key: string];
}>();

const { can } = usePermissions();
</script>
