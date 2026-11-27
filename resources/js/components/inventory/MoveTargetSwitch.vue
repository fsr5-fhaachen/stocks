<template>
  <!-- only with the right to change the home; without it every move changes the place -->
  <div
    v-if="can('inventory.edit')"
    role="radiogroup"
    aria-label="Was das Ziel wird"
    class="inline-flex rounded-md bg-surface-muted p-0.5 text-sm ring-1 ring-inset ring-line"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      :aria-checked="model === option.value"
      :class="[
        'rounded px-3 py-1.5 font-medium',
        model === option.value
          ? 'bg-surface text-ink shadow-sm'
          : 'text-ink-muted hover:text-ink',
      ]"
      @click="model = option.value"
    >
      {{ option.label }}
    </button>
  </div>
</template>

<script setup lang="ts">
export type MoveMode = "place" | "home" | "both";

/**
 * Switch "Ort / Stammplatz / beides" of the move flows (scan card, page "Umlagern", dialog on the
 * item page): the target becomes the place, the home or both (App\Http\Controllers\Inventory\
 * MoveController). Starts with "Ort"; hidden without inventory.edit.
 */
const model = defineModel<MoveMode>({ default: "place" });
const { can } = usePermissions();

const options: { value: MoveMode; label: string }[] = [
  { value: "place", label: "Ort" },
  { value: "home", label: "Stammplatz" },
  { value: "both", label: "Beides" },
];
</script>
