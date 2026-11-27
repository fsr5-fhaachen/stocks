<template>
  <!-- the label as it is printed, only to look at -->
  <div class="rounded-lg bg-surface-muted p-4">
    <canvas
      ref="canvas"
      class="mx-auto block max-w-full bg-white shadow-md"
      :style="{
        width: `${template.width_mm * 6}px`,
        imageRendering: monochrome ? 'pixelated' : 'auto',
      }"
      role="img"
      :aria-label="`Etikett ${data.title}`"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { drawLabel, loadImages, toMonochrome } from "../../labels/render";

/**
 * Picture of one label without editing, e.g. in the summary of the guided capture. Thermal
 * printers are shown in their dots and in black and white, like the editor does.
 */
const props = withDefaults(
  defineProps<{
    template: { width_mm: number; height_mm: number; elements: LabelElement[] };
    data: LabelData;
    logoUrl: string | null;
    printer?: PrinterData | null;
  }>(),
  { printer: null },
);

const canvas = ref<HTMLCanvasElement | null>(null);
const monochrome = computed(() => props.printer?.driver === "niimbot");

const draw = async () => {
  const images = await loadImages(
    [props.template],
    [props.data],
    props.logoUrl,
  );
  if (!canvas.value) {
    return;
  }
  const pxPerMm = monochrome.value
    ? (props.printer!.model?.dpi ?? 203) / 25.4
    : 12 * (window.devicePixelRatio || 1);
  drawLabel(canvas.value, props.template, props.data, {
    pxPerMm,
    images,
    logoUrl: props.logoUrl,
  });
  if (monochrome.value) {
    toMonochrome(canvas.value);
  }
};

onMounted(draw);
watch(() => [props.template, props.data, props.printer, props.logoUrl], draw, {
  deep: true,
});
</script>
