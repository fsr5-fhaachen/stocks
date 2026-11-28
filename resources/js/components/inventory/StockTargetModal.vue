<template>
  <UiModal title="Sollmenge festlegen" @close="$emit('close')">
    <form class="space-y-5" @submit.prevent="submit">
      <p class="text-sm text-ink-muted">
        Wie viel von einem Mengenartikel in
        <span class="font-mono">{{ placeCode }}</span> liegen soll, auch wenn
        dort noch nichts liegt. „Auffüllen“ holt dann die Differenz aus dem
        Eingang.
      </p>
      <InventoryCameraScanner
        v-if="cameraOpen"
        autostart
        class="aspect-video w-full"
        @scan="scanned"
        @stopped="cameraOpen = false"
      />
      <UiButton
        v-else
        type="button"
        variant="secondary"
        icon="camera"
        class="w-full"
        @click="cameraOpen = true"
      >
        Mengenartikel mit der Kamera scannen
      </UiButton>
      <UiInput
        v-model="form.code"
        label="Mengenartikel"
        placeholder="Nummer oder Barcode der Verpackung"
        required
        :error="form.errors.code ?? form.errors.item"
      />
      <UiInput
        v-model="form.target_quantity"
        label="Sollmenge"
        type="number"
        inputmode="numeric"
        min="1"
        required
        :error="
          form.errors.target_quantity ??
          form.errors.at ??
          form.errors.place_code
        "
      />
      <UiButton type="submit" class="w-full" :disabled="form.processing">
        Festlegen
      </UiButton>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { ref } from "vue";

/**
 * Target quantity ("Sollmenge") of a bulk item at a box or location, also for an item that does
 * not lie there yet. Opened on box and location pages.
 */
const props = defineProps<{
  // code of the box or location
  placeCode: string;
}>();
const emit = defineEmits<{ close: []; done: [] }>();

const cameraOpen = ref(false);
const form = useForm({
  code: "",
  place_code: props.placeCode,
  target_quantity: null as number | null,
});

// camera or hand scanner (without a focused field) fill in the item
const scanned = (code: string) => {
  cameraOpen.value = false;
  form.code = code;
};
useScannerInput(scanned);

const submit = () =>
  form.post("/inventory/stocks/target", {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => emit("done"),
  });
</script>
