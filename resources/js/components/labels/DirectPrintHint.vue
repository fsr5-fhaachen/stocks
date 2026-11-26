<template>
  <!-- iPhone and iPad: Safari (and every browser there) has no bluetooth, the browser Bluefy has -->
  <p v-if="bluefy" class="text-sm text-ink-muted">
    Auf dem iPhone und iPad druckt Stocks direkt per Bluetooth im Browser
    Bluefy: Stocks dort öffnen und anmelden.
    <UiLink :href="BLUEFY_URL">Bluefy im App Store</UiLink>. Bis dahin das Bild
    herunterladen und mit der Niimbot-App drucken.
  </p>
  <p v-else class="text-sm text-ink-muted">
    Direkt drucken geht nur in Chrome oder Edge am PC und unter Android. Hier
    das Bild herunterladen und mit der Niimbot-App drucken.
  </p>
</template>

<script setup lang="ts">
import { computed } from "vue";

/**
 * Hint below the Niimbot buttons when this browser can not print directly. On iPhone and iPad it
 * points to Bluefy, a browser with Web Bluetooth; not on a kiosk whose platform rules out direct
 * printing (it stays in its own browser).
 */
const BLUEFY_URL = "https://apps.apple.com/app/id1492822055";

const { surface } = useSurface();

// iPads report themselves as Mac, but have a touch screen
const isAppleMobile = () =>
  /iPhone|iPad|iPod/.test(navigator.userAgent) ||
  (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

const bluefy = computed(
  () => surface.value?.direct_print !== false && isAppleMobile(),
);
</script>
