<template>
  <!-- both logos: the colour mode switches without reloading -->
  <template v-if="light && dark">
    <img :src="light" alt="" :class="['dark:hidden', imgClass]" />
    <img :src="dark" alt="" :class="['hidden dark:block', imgClass]" />
  </template>
  <img
    v-else-if="light || dark"
    :src="(light || dark)!"
    alt=""
    :class="imgClass"
  />
</template>

<script setup lang="ts">
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

/**
 * Logo of the organization: the logo for light backgrounds in light mode, the logo for dark
 * backgrounds in dark mode. With only one of them it is shown in both modes. Renders nothing
 * without logo.
 */
defineProps({
  // classes of the image (size, object-fit)
  imgClass: { type: String, default: "" },
});

const organization = computed(() => usePage<SharedProps>().props.organization);
const light = computed(() => organization.value.logo_light_url);
const dark = computed(() => organization.value.logo_url);
</script>
