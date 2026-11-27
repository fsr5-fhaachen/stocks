<template>
  <div class="space-y-5">
    <p class="text-sm text-ink-muted">
      {{
        kind === "place"
          ? "Wo liegt der Gegenstand jetzt? QR-Code des Standorts oder der Box scannen oder die Kennziffer tippen."
          : "Wohin gehört der Gegenstand, wenn er aufgeräumt wird? Scannen oder tippen."
      }}
    </p>

    <!-- only the scanner of the current step runs; it stops when the step is left -->
    <InventoryCameraScanner
      v-if="cameraOpen"
      autostart
      class="aspect-video w-full"
      @scan="scanned"
      @stopped="cameraOpen = false"
    />
    <UiButton
      v-else
      variant="secondary"
      size="lg"
      icon="camera"
      class="w-full"
      @click="cameraOpen = true"
    >
      QR-Code scannen
    </UiButton>

    <InventoryPlaceInput
      :key="inputKey"
      v-model="code"
      :label="kind === 'place' ? 'Liegt in' : 'Stammplatz'"
      :initial="place"
      :error="scanError ?? error"
      @select="place = $event"
    />

    <div
      v-if="place"
      class="flex items-center gap-3 rounded-md bg-surface-muted p-3 text-sm"
    >
      <FontAwesomeIcon
        :icon="['fas', place.type === 'location' ? 'location-dot' : 'box']"
        class="text-ink-muted"
      />
      <span class="min-w-0 flex-1">
        <span class="font-mono font-semibold">{{ place.code }}</span>
        <span v-if="place.name" class="ml-1 text-ink-muted">{{
          place.name
        }}</span>
      </span>
      <button
        type="button"
        class="text-ink-muted hover:text-ink"
        aria-label="Ort entfernen"
        @click="clear"
      >
        <FontAwesomeIcon :icon="['fas', 'xmark']" />
      </button>
    </div>

    <slot />
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from "vue";

/**
 * Step "Ort" or "Stammplatz" of the guided capture: scan the code of a location or a box, or type
 * it (with suggestions). The server checks the place again when the item is created.
 */
withDefaults(defineProps<{ kind: "place" | "home"; error?: string | null }>(), {
  error: null,
});

const code = defineModel<string>("code", { required: true });
const place = defineModel<Place | null>("place", { required: true });

const { url } = useSurface();
const cameraOpen = ref(false);
const scanError = ref<string | null>(null);
// a scan gives the field a new start value
const inputKey = ref(0);

// typing something else drops the found place
watch(code, (value) => {
  if (
    place.value &&
    value.trim().toUpperCase() !== place.value.code.toUpperCase()
  ) {
    place.value = null;
  }
});

const scanned = async (scannedCode: string) => {
  scanError.value = null;
  const response = await fetch(
    url(`/inventory/lookup?code=${encodeURIComponent(scannedCode)}`),
    { headers: { Accept: "application/json" } },
  );
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    scanError.value = data.message ?? "Der Code wurde nicht gefunden.";
    return;
  }
  if (data.type === "item" && !data.is_container) {
    scanError.value = `${data.number} ist keine Box. Scanne einen Standort oder eine Box.`;
    return;
  }
  if (data.type === "item" && data.retired) {
    scanError.value = `${data.number} ist ausgemustert.`;
    return;
  }
  if (data.type !== "item" && data.type !== "location") {
    scanError.value = data.message ?? "Das ist kein Ort.";
    return;
  }
  if (
    data.type === "location" &&
    ["address", "building"].includes(data.location_type)
  ) {
    scanError.value = `${data.full_code} ist ein Gebäude bzw. eine Adresse. Gegenstände liegen in einem Raum, Lagerplatz oder Fach.`;
    return;
  }
  // set both from the same value: reading place.value right after setting it still gives the
  // old place, the model only changes once the page renders again
  const found: Place =
    data.type === "item"
      ? { type: "container", id: data.id, code: data.number, name: data.name }
      : {
          type: "location",
          id: data.id,
          code: data.full_code,
          name: data.name,
        };
  place.value = found;
  code.value = found.code;
  inputKey.value++;
  cameraOpen.value = false;
};

const clear = () => {
  place.value = null;
  code.value = "";
  scanError.value = null;
  inputKey.value++;
};
</script>
