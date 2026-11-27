<template>
  <UiPageHeader
    title="Wareneingang"
    description="Neue Stücke vorhandener Mengenartikel kommen ins Eingang. Von dort werden sie eingeräumt oder in Boxen gepackt."
  >
    <UiButton variant="secondary" icon="plus" :href="`/inventory/capture`">
      Neuer Mengenartikel
    </UiButton>
  </UiPageHeader>

  <div class="grid gap-6 lg:grid-cols-2">
    <UiCard title="Einbuchen">
      <form class="space-y-5" @submit.prevent="receive">
        <InventoryCameraScanner
          v-if="cameraOpen"
          autostart
          class="aspect-video w-full"
          @scan="scanWithCamera"
          @stopped="cameraOpen = false"
        />
        <div class="flex items-end gap-2">
          <UiInput
            v-model="form.code"
            class="flex-1"
            label="Mengenartikel"
            placeholder="Nummer oder Barcode der Verpackung"
            required
            :error="form.errors.code ?? form.errors.item"
          />
          <UiButton
            v-if="!cameraOpen"
            type="button"
            variant="secondary"
            icon="camera"
            aria-label="Kamera"
            @click="cameraOpen = true"
          />
        </div>
        <UiInput
          v-model="form.quantity"
          label="Menge"
          type="number"
          inputmode="numeric"
          min="1"
          required
          :error="form.errors.quantity"
        />
        <UiInput
          v-model="form.note"
          label="Notiz (optional)"
          placeholder="z. B. Bestellung Reichelt"
          :error="form.errors.note"
        />
        <UiButton type="submit" class="w-full" :disabled="form.processing">
          Einbuchen
        </UiButton>
      </form>
    </UiCard>

    <UiCard
      title="Im Eingang"
      :description="
        incoming.length
          ? `${incoming.reduce((sum, row) => sum + row.quantity, 0)} Stück in ${incoming.length} Artikeln`
          : undefined
      "
      flush
    >
      <ul v-if="incoming.length" class="divide-y divide-line">
        <li
          v-for="row in incoming"
          :key="row.id"
          class="flex items-center justify-between gap-3 px-4 py-3 sm:px-6"
        >
          <div class="min-w-0 space-y-1">
            <p class="truncate">
              <UiLink :href="`/inventory/items/${row.id}`">{{
                row.name
              }}</UiLink>
              <span class="ml-2 font-mono text-xs text-ink-muted">{{
                row.number
              }}</span>
            </p>
            <p class="text-sm text-ink-muted">
              <UiBadge>{{ row.quantity }} {{ row.unit }}</UiBadge>
              <template v-if="row.since">
                · seit <UiDateTime :value="row.since" />
              </template>
              <template v-if="row.home">
                · Stammplatz
                <span class="font-mono">{{ row.home.code }}</span>
              </template>
            </p>
          </div>
          <UiButton
            v-if="can('inventory.move')"
            size="sm"
            variant="secondary"
            icon="box-open"
            class="shrink-0"
            @click="packing = row"
          >
            Einräumen
          </UiButton>
        </li>
      </ul>
      <UiEmptyState
        v-else
        icon="truck-ramp-box"
        title="Im Eingang liegt nichts"
      />
    </UiCard>
  </div>

  <!-- from "Eingang" into a box or to a location; the home is suggested -->
  <InventoryPackModal
    v-if="packing"
    :item="packing"
    from="IN"
    title="Einräumen"
    @close="packing = null"
    @done="packing = null"
  />
</template>

<script setup lang="ts">
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";

type IncomingRow = {
  id: number;
  number: string;
  name: string;
  unit: string | null;
  quantity: number;
  since: string | null;
  home: Place | null;
  stocks: StockEntry[];
};

/**
 * "Wareneingang": new pieces of a bulk item into "Eingang" (by its number or the barcode of the
 * packaging), and the list of what lies there, to put it away.
 */
defineProps<{ incoming: IncomingRow[] }>();

const { can } = usePermissions();
const cameraOpen = ref(false);
const packing = ref<IncomingRow | null>(null);

const form = useForm({
  code: "",
  quantity: 1 as number | null,
  note: "",
});
const receive = () =>
  form.post("/inventory/stock-in", {
    preserveScroll: true,
    onSuccess: () => {
      form.reset("code", "quantity");
    },
  });

// a hand scanner without a focused field, or the camera, fills in the item
// (while the dialog "Einräumen" is open, the scan is its target)
useScannerInput((code) => {
  if (!packing.value) {
    form.code = code;
  }
});
const scanWithCamera = (code: string) => {
  form.code = code;
  cameraOpen.value = false;
};
</script>
