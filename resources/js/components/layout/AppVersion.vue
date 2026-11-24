<template>
  <div v-if="app">
    <!-- collapsed sidebar: only icons, the version in the tooltip -->
    <div v-if="collapsed" class="flex justify-center">
      <a
        v-if="app.update"
        :href="app.update.url"
        target="_blank"
        rel="noopener"
        class="rounded-md p-2 text-accent hover:bg-surface-muted"
        :title="`Update verfügbar: ${app.update.tag} (installiert: ${label})`"
        :aria-label="`Update verfügbar: ${app.update.tag}`"
      >
        <FontAwesomeIcon :icon="['fas', 'circle-arrow-up']" />
      </a>
      <span
        v-else
        class="p-2 text-xs text-ink-muted"
        :title="`Stocks ${label}`"
        :aria-label="`Stocks ${label}`"
      >
        <FontAwesomeIcon :icon="['fas', 'tag']" />
      </span>
    </div>

    <div v-else class="space-y-2 px-2">
      <div
        class="flex items-center justify-between gap-2 text-xs text-ink-muted"
      >
        <span>Stocks</span>
        <UiLink v-if="app.commit_url" :href="app.commit_url" variant="muted">
          {{ label }}
        </UiLink>
        <span v-else class="font-medium">{{ label }}</span>
      </div>
      <a
        v-if="app.update"
        :href="app.update.url"
        target="_blank"
        rel="noopener"
        class="flex items-center justify-center gap-2 rounded-md bg-accent px-3 py-2 text-sm font-medium text-white hover:bg-accent-hover"
        :title="`${app.update.tag} ansehen`"
      >
        <FontAwesomeIcon :icon="['fas', 'circle-arrow-up']" />
        Update verfügbar
      </a>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Installed version below the menu and, for those who manage the instance, a button to a newer
 * release on GitHub (App\Support\AppVersion). Not on the kiosk: the prop is null there.
 */
defineProps({
  // collapsed sidebar: icons only
  collapsed: { type: Boolean, default: false },
});

const page = usePage<SharedProps>();
const app = computed(() => page.props.app);

// "v0.3.0", on the dev instance "dev · a1b2c3d"
const label = computed(() =>
  app.value?.commit
    ? `dev · ${app.value.commit.slice(0, 7)}`
    : (app.value?.version ?? ""),
);
</script>
