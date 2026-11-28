<template>
  <UiModal :title="`${item.number} ausmustern`" @close="$emit('close')">
    <form class="space-y-5" @submit.prevent="submit()">
      <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
        <span class="font-semibold">{{ item.name }}</span>
        <span v-if="placeName" class="text-ink-muted">{{ placeName }}</span>
      </p>
      <!-- bulk items: pieces from the given place; single items and boxes as a whole -->
      <UiInput
        v-if="item.kind === 'bulk'"
        v-model="form.quantity"
        label="Menge"
        type="number"
        inputmode="numeric"
        min="1"
        required
        :hint="
          there !== null
            ? `Dort liegen ${there} ${item.unit ?? ''}.`
            : undefined
        "
        :error="form.errors.quantity ?? form.errors.from"
      />
      <UiSelect
        v-model="form.reason"
        label="Grund"
        :options="reasons"
        :placeholder="null"
        :error="form.errors.reason"
      />
      <UiInput
        v-model="form.note"
        label="Notiz (optional)"
        :error="form.errors.note"
      />
      <p v-if="form.errors.item" class="text-sm text-red-600">
        {{ form.errors.item }}
      </p>
      <InventoryReservedConfirm
        :message="form.errors.confirm"
        :processing="form.processing"
        @confirm="submit(true)"
      />
      <UiButton
        type="submit"
        variant="danger"
        class="w-full"
        :disabled="form.processing"
      >
        Ausmustern
      </UiButton>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";

/**
 * "Ausmustern" from a list: an item or box as a whole, or pieces of a bulk item from one place
 * (a box, a location). Used in the contents of a box, on location pages and on the scan card.
 */
const props = withDefaults(
  defineProps<{
    item: {
      id: number;
      number: string;
      name: string;
      unit: string | null;
      kind: "single" | "bulk" | string;
    };
    reasons: Option[];
    // bulk item: place key of the pieces and how many lie there
    from?: string | null;
    there?: number | null;
    placeName?: string | null;
  }>(),
  { from: null, there: null, placeName: null },
);
const emit = defineEmits<{ close: []; done: [] }>();
const { url } = useSurface();

const form = useForm({
  reason: "defective",
  note: "",
  quantity: (props.item.kind === "bulk" ? 1 : null) as number | null,
  from: props.from,
});

// confirmed: asked before and answered "Ja, trotzdem" (the item is booked)
const submit = (confirmed = false) =>
  form
    .transform((data) => ({
      ...data,
      quantity: props.item.kind === "bulk" ? data.quantity : null,
      confirmed,
    }))
    .post(url(`/inventory/items/${props.item.id}/retire`), {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => emit("done"),
    });
</script>
