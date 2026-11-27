<template>
  <div class="grid gap-5 sm:grid-cols-2">
    <UiInput
      v-model="form.name"
      label="Name"
      required
      class="sm:col-span-2"
      :error="errors.name"
    />
    <UiSelect
      v-model="form.category_id"
      label="Kategorie"
      :options="categories"
      placeholder="keine"
    />
    <UiSelect
      v-model="form.kind"
      label="Art"
      :options="kinds"
      :placeholder="null"
    />
    <template v-if="form.kind === 'bulk'">
      <UiInput
        v-model="form.quantity"
        label="Menge"
        type="number"
        inputmode="numeric"
        required
        :hint="quantityHint"
        :error="errors.quantity"
      />
      <div>
        <UiSelect
          v-model="form.unit"
          label="Einheit"
          :options="units"
          :placeholder="null"
        />
        <p v-if="errors.unit" class="mt-1 text-sm text-red-600">
          {{ errors.unit }}
        </p>
      </div>
    </template>
    <!-- e.g. "Anzahl gleicher Stücke" of the guided capture -->
    <slot name="kind" />
    <UiSelect
      v-if="conditions"
      v-model="form.condition_id"
      label="Zustand"
      :options="conditions"
      :placeholder="null"
    />

    <!-- the guided capture keeps the rarely used fields behind "Mehr" -->
    <div v-if="collapsible" class="sm:col-span-2">
      <button
        type="button"
        class="flex items-center gap-2 text-sm font-medium text-ink-muted hover:text-ink"
        :aria-expanded="moreOpen"
        @click="moreOpen = !moreOpen"
      >
        <FontAwesomeIcon
          :icon="['fas', 'chevron-down']"
          :class="['transition-transform', moreOpen ? 'rotate-180' : '']"
        />
        Mehr
      </button>
    </div>
    <template v-if="!collapsible || moreOpen">
      <UiTextarea
        v-model="form.description"
        label="Beschreibung"
        class="sm:col-span-2"
        :error="errors.description"
      />
      <UiTextarea
        v-model="form.notes"
        label="Notiz"
        class="sm:col-span-2"
        :rows="2"
        :error="errors.notes"
      />
      <slot name="more" />
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from "vue";

/**
 * Fields of an item shared by the item form and the guided capture: name, category, kind with
 * quantity and unit, condition (new items), description and notes.
 */
export type ItemFieldsForm = {
  name: string;
  category_id: number | string | null;
  kind: string;
  quantity: number | string | null;
  unit: string | number | null;
  condition_id: number | string | null;
  description: string;
  notes: string;
};

const props = withDefaults(
  defineProps<{
    errors: Partial<Record<string, string>>;
    categories: Option[];
    kinds: Option[];
    units: Option[];
    // only for new items; existing ones change it with "Zustand ändern"
    conditions?: Option[] | null;
    quantityHint?: string | null;
    // description and notes behind "Mehr"
    collapsible?: boolean;
  }>(),
  { conditions: null, quantityHint: null, collapsible: false },
);

// the form object of the page; the fields change it in place (the page passes it with :form)
const form = defineModel<ItemFieldsForm>("form", { required: true });

// open from the start if something hidden is already filled in or wrong
const moreOpen = ref(
  !!(form.value.description || form.value.notes) ||
    !!(props.errors.description || props.errors.notes),
);
// e.g. a description taken from a product database is shown, not hidden
watch(
  () => form.value.description,
  (value) => {
    if (value) {
      moreOpen.value = true;
    }
  },
);
</script>
