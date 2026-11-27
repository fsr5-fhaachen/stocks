<template>
  <UiModal :title="`${placeName} zählen`" @close="$emit('close')">
    <form class="space-y-5" @submit.prevent="submit">
      <UiInput
        v-model="form.quantity"
        label="Wie viele liegen dort?"
        type="number"
        inputmode="numeric"
        min="0"
        required
        :hint="`Bisher ${stock.quantity} ${unit ?? ''}. Der Gesamtbestand passt sich an.`"
        :error="form.errors.quantity ?? form.errors.place"
      />
      <!-- "Eingang" is emptied, not filled: no target there -->
      <UiInput
        v-if="stock.place"
        v-model="form.target_quantity"
        label="Sollmenge (optional)"
        type="number"
        inputmode="numeric"
        min="1"
        hint="Liegt dort weniger, ist die Stelle ein Mangel; „Auffüllen“ holt die Differenz aus dem Eingang."
        :error="form.errors.target_quantity"
      />
      <UiInput v-model="form.note" label="Notiz (optional)" />
      <UiButton type="submit" class="w-full" :disabled="form.processing">
        Speichern
      </UiButton>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed } from "vue";

/**
 * Count a place of a bulk item ("Zählen") and set its target quantity ("Sollmenge").
 */
const props = defineProps<{
  itemId: number;
  unit: string | null;
  stock: StockEntry;
}>();
const emit = defineEmits<{ close: []; done: [] }>();

const placeName = computed(() => props.stock.place?.code ?? "Eingang");
const form = useForm({
  place: props.stock.key,
  quantity: props.stock.quantity as number | null,
  target_quantity: props.stock.target_quantity as number | null | string,
  note: "",
});

const submit = () =>
  form
    .transform((data) => ({
      ...data,
      target_quantity:
        data.target_quantity === "" || data.target_quantity === null
          ? null
          : Number(data.target_quantity),
    }))
    .post(`/inventory/items/${props.itemId}/stocks`, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => emit("done"),
    });
</script>
