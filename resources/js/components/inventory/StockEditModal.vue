<template>
  <UiModal :title="`Sollmenge in ${placeName}`" @close="$emit('close')">
    <form class="space-y-5" @submit.prevent="submit">
      <UiInput
        v-model="form.target_quantity"
        label="Sollmenge"
        type="number"
        inputmode="numeric"
        min="1"
        :hint="`Dort liegen ${stock.quantity} ${unit ?? ''}. Liegt weniger als die Sollmenge dort, ist die Stelle ein Mangel; „Auffüllen“ holt die Differenz aus dem Eingang. Leer: keine Sollmenge.`"
        :error="form.errors.target_quantity ?? form.errors.place"
      />
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
 * Target quantity ("Sollmenge") of a place of a bulk item, from its row (item page, box contents,
 * location).
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
  target_quantity: props.stock.target_quantity as number | null | string,
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
