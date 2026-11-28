<template>
  <UiModal :title="title" @close="$emit('close')">
    <form class="space-y-5" @submit.prevent="submit">
      <UiInput
        v-model="form.quantity"
        label="Menge"
        type="number"
        inputmode="numeric"
        min="1"
        required
        :hint="hint"
        :error="form.errors.quantity ?? form.errors.place"
      />
      <UiInput
        v-model="form.note"
        label="Notiz (optional)"
        :placeholder="incoming ? 'z. B. Bestellung Reichelt' : undefined"
        :error="form.errors.note"
      />
      <UiButton type="submit" class="w-full" :disabled="form.processing">
        {{ incoming ? "Einbuchen" : "Dazutun" }}
      </UiButton>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed } from "vue";

/**
 * "+" at a place of a bulk item: pieces out of "Eingang" to this place; at "Eingang" itself new
 * pieces ("Wareneingang").
 */
const props = withDefaults(
  defineProps<{
    itemId: number;
    unit: string | null;
    stock: StockEntry;
    // pieces in "Eingang", if known
    incomingQuantity?: number | null;
  }>(),
  { incomingQuantity: null },
);
const emit = defineEmits<{ close: []; done: [] }>();

const incoming = computed(() => props.stock.key === "IN");
const title = computed(() =>
  incoming.value
    ? "Wareneingang"
    : `Aus dem Eingang in ${props.stock.place?.code ?? ""}`,
);
const hint = computed(() => {
  if (incoming.value) {
    return "Neue Stücke kommen ins Eingang, der Bestand steigt.";
  }
  const missing =
    props.stock.target_quantity !== null
      ? Math.max(0, props.stock.target_quantity - props.stock.quantity)
      : 0;
  return [
    props.incomingQuantity !== null
      ? `Im Eingang liegen ${props.incomingQuantity} ${props.unit ?? ""}.`
      : "Die Stücke gehen im Eingang ab.",
    missing ? `Bis zur Sollmenge fehlen ${missing}.` : "",
  ]
    .filter(Boolean)
    .join(" ");
});

const form = useForm({
  place: props.stock.key,
  quantity: (props.stock.target_quantity !== null &&
  props.stock.target_quantity > props.stock.quantity
    ? props.stock.target_quantity - props.stock.quantity
    : 1) as number | null,
  note: "",
});

const submit = () =>
  form.post(`/inventory/items/${props.itemId}/stocks/add`, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => emit("done"),
  });
</script>
