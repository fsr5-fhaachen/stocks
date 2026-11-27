<template>
  <UiModal :title="title" @close="$emit('close')">
    <form class="space-y-5" @submit.prevent="submit">
      <!-- one camera for both scans: first the bulk item (if not given), then the target -->
      <InventoryCameraScanner
        v-if="cameraOpen"
        autostart
        class="aspect-video w-full"
        @scan="scanned"
        @stopped="cameraOpen = false"
      />
      <UiButton
        v-else-if="!chosen || !target"
        type="button"
        variant="secondary"
        icon="camera"
        class="w-full"
        @click="cameraOpen = true"
      >
        {{
          chosen
            ? "Ziel mit der Kamera scannen"
            : "Mengenartikel mit der Kamera scannen"
        }}
      </UiButton>

      <!-- without a given item: scan or type the number of the bulk item first -->
      <div v-if="!props.item" class="flex items-end gap-2">
        <UiInput
          v-model="itemCode"
          class="flex-1"
          label="Mengenartikel"
          placeholder="Nummer scannen oder tippen"
          :error="itemError ?? undefined"
          @keydown.enter.prevent="findItem"
        />
        <UiButton
          type="button"
          variant="secondary"
          icon="magnifying-glass"
          aria-label="Suchen"
          @click="findItem"
        />
      </div>
      <p v-if="chosen" class="flex flex-wrap items-baseline gap-x-2 text-sm">
        <span class="font-semibold">{{ chosen.name }}</span>
        <span class="font-mono text-ink-muted">{{ chosen.number }}</span>
      </p>

      <template v-if="chosen">
        <UiSelect
          v-if="sourceOptions.length > 1"
          v-model="form.from"
          label="Von"
          :options="sourceOptions"
          :placeholder="null"
          :error="form.errors.from"
          @update:model-value="form.quantity = there"
        />
        <UiAlert v-else-if="!sourceOptions.length" type="warning">
          Von {{ chosen.number }} liegt gerade nichts an einem Platz.
        </UiAlert>
        <UiInput
          v-model="form.quantity"
          label="Menge"
          type="number"
          inputmode="numeric"
          required
          :hint="`${placeName(form.from)} liegen ${there} ${chosen.unit ?? ''}.`"
          :error="form.errors.quantity"
        />
        <!-- a hand scanner types into the field; typing a name suggests places -->
        <InventoryPlaceInput
          v-if="!target"
          :key="targetKey"
          v-model="form.target_code"
          label="Wohin? (Box oder Standort)"
          placeholder="Kennziffer oder Boxnummer"
          required
          :hint="
            chosen.home
              ? `Vorschlag: Stammplatz ${chosen.home.code}`
              : 'Kennziffer, Name oder Boxnummer'
          "
          :error="form.errors.target_code ?? form.errors.target"
        />
        <UiButton
          v-if="!target && chosen.home && form.target_code !== chosen.home.code"
          type="button"
          variant="secondary"
          size="sm"
          icon="house"
          @click="useHome"
        >
          Stammplatz {{ chosen.home.code }} nehmen
        </UiButton>
        <p v-else class="text-sm text-ink-muted">
          Ziel: <span class="font-mono">{{ target }}</span>
        </p>
        <p
          v-if="form.errors.item || form.errors.to"
          class="text-sm text-red-600"
        >
          {{ form.errors.item ?? form.errors.to }}
        </p>
        <UiButton
          type="submit"
          class="w-full"
          :disabled="form.processing || !sourceOptions.length"
        >
          {{ title }}
        </UiButton>
      </template>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

type PackItem = {
  id: number;
  number: string;
  name: string;
  unit: string | null;
  stocks: StockEntry[];
  home?: Place | null;
};

/**
 * "Einpacken": pieces of a bulk item from one of its places ("Eingang" first) into a box or to a
 * location. Opened on the item page (target free), on a box or location page (target given, the
 * item is scanned) and on the page "Wareneingang". The move itself is POST /inventory/move.
 */
const props = withDefaults(
  defineProps<{
    // the bulk item; null: asked in the dialog
    item?: PackItem | null;
    // code of the target box or location; null: asked in the dialog
    target?: string | null;
    // place key the pieces come from; null: "Eingang" if it has pieces, else the first place
    from?: string | null;
    title?: string;
  }>(),
  { item: null, target: null, from: null, title: "Einpacken" },
);
const emit = defineEmits<{ close: []; done: [] }>();
const { url } = useSurface();

const found = ref<PackItem | null>(null);
const cameraOpen = ref(false);
// a new key shows a scanned target in the place field
const targetKey = ref(0);
const chosen = computed(() => props.item ?? found.value);
const itemCode = ref("");
const itemError = ref<string | null>(null);

const withPieces = computed(() =>
  (chosen.value?.stocks ?? []).filter((stock) => stock.quantity > 0),
);
const sourceOptions = computed<Option[]>(() =>
  withPieces.value.map((stock) => ({
    value: stock.key,
    label: `${stock.place ? stock.place.code : "Eingang"} (${stock.quantity} ${chosen.value?.unit ?? ""})`,
  })),
);
const firstSource = () =>
  props.from ??
  withPieces.value.find((stock) => stock.key === "IN")?.key ??
  withPieces.value[0]?.key ??
  null;
const stockAt = (key: string | null) =>
  withPieces.value.find((stock) => stock.key === key)?.quantity ?? 0;

const form = useForm({
  item_id: 0,
  from: null as string | null,
  quantity: null as number | null,
  target_code: props.target ?? "",
});
const there = computed(() => stockAt(form.from));
const placeName = (key: string | null) => {
  const place = withPieces.value.find((stock) => stock.key === key)?.place;
  return place ? `In ${place.code}` : "Im Eingang";
};
const useHome = () => {
  form.target_code = chosen.value?.home?.code ?? "";
  targetKey.value++;
};
// the camera or a hand scanner (without a focused field) fills the field that is open: the item
// first, then the target
const scanned = (code: string) => {
  cameraOpen.value = false;
  if (!chosen.value) {
    itemCode.value = code;
    findItem();
    return;
  }
  if (props.target) {
    return;
  }
  form.target_code = code;
  targetKey.value++;
};
useScannerInput(scanned);

const start = () => {
  form.from = firstSource();
  form.quantity = stockAt(form.from);
};
if (props.item) {
  start();
}

const findItem = async () => {
  itemError.value = null;
  const code = itemCode.value.trim();
  if (!code) {
    return;
  }
  const response = await fetch(
    url(`/inventory/lookup?code=${encodeURIComponent(code)}`),
    { headers: { Accept: "application/json" } },
  );
  const data = await response.json();
  if (!response.ok) {
    itemError.value = data.message;
  } else if (data.type !== "item" || data.kind !== "bulk") {
    itemError.value = `${data.number ?? data.full_code} ist kein Mengenartikel.`;
  } else if (data.retired) {
    itemError.value = `${data.number} ist ausgemustert.`;
  } else {
    found.value = data;
    start();
  }
};

const submit = () => {
  if (!chosen.value) {
    return;
  }
  form.item_id = chosen.value.id;
  form
    .transform((data) => ({ ...data, mode: "place" }))
    .post(url("/inventory/move"), {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => emit("done"),
    });
};
</script>
