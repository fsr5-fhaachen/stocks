<template>
  <UiModal title="Verbraucht" @close="$emit('close')">
    <form class="space-y-5" @submit.prevent="submit()">
      <!-- the place the pieces come from; only asked when there is more than one -->
      <UiSelect
        v-if="sourceOptions.length > 1"
        v-model="form.from"
        label="Von"
        :options="sourceOptions"
        :placeholder="null"
        :error="form.errors.from"
      />
      <UiInput
        v-model="form.quantity"
        label="Wie viel wurde verbraucht?"
        type="number"
        inputmode="numeric"
        required
        :hint="hint"
        :error="form.errors.quantity"
      />
      <UiInput
        v-model="form.note"
        label="Wofür? (optional)"
        :error="form.errors.note"
      />
      <InventoryReservedConfirm
        :message="form.errors.confirm"
        :processing="form.processing"
        @confirm="submit(true)"
      />
      <UiButton type="submit" class="w-full" :disabled="form.processing">
        Als verbraucht ausbuchen
      </UiButton>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed } from "vue";

/**
 * Mark pieces of a consumable as used up, from one of its places. Used on the item page, the scan
 * page and in the contents of boxes.
 */
const props = withDefaults(
  defineProps<{
    item: {
      id: number;
      quantity: number;
      quantity_here?: number | null;
      unit: string | null;
    };
    // place key the pieces come from (item_stocks.place_key); null: the server takes the default
    from?: string | null;
    // places of the item to choose from; empty: no choice
    sources?: StockEntry[];
  }>(),
  { from: null, sources: () => [] },
);
const emit = defineEmits<{ close: []; done: [] }>();
const { url } = useSurface();

const sourceOptions = computed<Option[]>(() =>
  props.sources
    .filter((stock) => stock.quantity > 0)
    .map((stock) => ({
      value: stock.key,
      label: `${stock.place ? stock.place.code : "Eingang"} (${stock.quantity} ${props.item.unit ?? ""})`,
    })),
);
const there = computed(
  () => props.sources.find((stock) => stock.key === form.from)?.quantity,
);
const hint = computed(() =>
  there.value !== undefined
    ? `Dort liegen ${there.value} ${props.item.unit ?? ""}`
    : `Vorhanden: ${props.item.quantity_here ?? props.item.quantity} ${props.item.unit ?? ""}`,
);

const form = useForm({
  quantity: 1 as number | null,
  note: "",
  from: props.from,
});
// confirmed: asked before and answered "Ja, trotzdem" (the item is booked)
const submit = (confirmed = false) =>
  form
    .transform((data) => ({ ...data, confirmed }))
    .post(url(`/inventory/items/${props.item.id}/consume`), {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => emit("done"),
    });
</script>
