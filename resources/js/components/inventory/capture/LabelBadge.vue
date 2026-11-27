<template>
  <UiBadge v-if="entry.no_label" variant="neutral">kein Etikett nötig</UiBadge>
  <UiBadge v-else :variant="entry.printed ? 'success' : 'warning'">
    {{ entry.printed ? "etikettiert" : "ohne Etikett" }}
  </UiBadge>
</template>

<script setup lang="ts">
import type { CapturedItem } from "../../../composables/useCapture";

/**
 * Label state of a captured item in the result and the round of the guided capture.
 */
defineProps<{ entry: CapturedItem }>();
</script>
