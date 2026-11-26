<template>
  <div
    v-if="current"
    class="fixed inset-0 z-[60] flex flex-col bg-gray-800 text-white"
    role="dialog"
    aria-modal="true"
    :aria-label="current.name"
  >
    <!-- top bar: name and close -->
    <div
      class="flex items-center gap-3 bg-gray-900 px-4 pb-3"
      style="padding-top: max(0.75rem, env(safe-area-inset-top))"
    >
      <FontAwesomeIcon
        :icon="['fas', isImage ? 'file-image' : 'file-pdf']"
        class="h-4 w-4 shrink-0 text-gray-400"
      />
      <span class="min-w-0 flex-1 truncate text-sm font-medium">{{
        current.name
      }}</span>
      <button
        type="button"
        class="-mr-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-gray-300 hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
        @click="close"
      >
        <span class="sr-only">Schließen</span>
        <FontAwesomeIcon :icon="['fas', 'xmark']" class="h-5 w-5" />
      </button>
    </div>

    <!-- pages; pinch (or double tap) zooms -->
    <div
      ref="scroller"
      class="relative min-h-0 flex-1 overflow-auto overscroll-contain"
      style="touch-action: pan-x pan-y; -webkit-overflow-scrolling: touch"
      @gesturestart.prevent="gestureStart"
      @gesturechange.prevent="gestureChange"
      @gestureend.prevent="gestureEnd"
      @dblclick="toggleZoom"
      @scroll.passive="updateCurrentPage"
    >
      <div
        ref="content"
        class="flex w-max min-w-full flex-col items-center gap-3 p-3"
        :style="gestureStyle"
      >
        <img
          v-for="(url, index) in imageUrls"
          :key="url"
          :src="url"
          :alt="current.files[index]?.name ?? current.name"
          class="block bg-white shadow-lg"
          :style="{ width: `${pageWidth}px` }"
        />
        <div ref="pages" class="flex flex-col items-center gap-3" />
      </div>

      <div
        v-if="current.loading || rendering"
        class="absolute inset-x-0 top-1/3 flex flex-col items-center gap-3 text-sm text-gray-300"
      >
        <FontAwesomeIcon
          :icon="['fas', 'rotate']"
          class="h-6 w-6 animate-spin"
        />
        {{
          current.loading ? "Datei wird geladen …" : "Vorschau wird erstellt …"
        }}
      </div>
      <div
        v-else-if="error"
        class="mx-auto mt-16 max-w-sm px-6 text-center text-sm text-gray-200"
      >
        <FontAwesomeIcon
          :icon="['fas', 'triangle-exclamation']"
          class="mb-3 h-6 w-6 text-yellow-400"
        />
        <p>{{ error }}</p>
      </div>
    </div>

    <!-- share: opens the share sheet of iOS (print, save to files, send) -->
    <button
      v-if="current.files.length"
      type="button"
      class="absolute flex h-14 w-14 items-center justify-center rounded-full bg-accent text-white shadow-lg ring-4 ring-black/10 hover:bg-accent-hover focus:outline-none focus-visible:ring-white active:scale-95"
      style="
        left: max(1rem, env(safe-area-inset-left));
        bottom: max(1rem, env(safe-area-inset-bottom));
      "
      @click="share"
    >
      <span class="sr-only">Teilen oder drucken</span>
      <FontAwesomeIcon
        :icon="['fas', 'arrow-up-from-bracket']"
        class="h-6 w-6"
      />
    </button>
    <!-- page counter, follows scrolling -->
    <div
      v-if="pageCount > 1"
      class="pointer-events-none absolute rounded-full bg-gray-900/80 px-3 py-1.5 text-sm font-medium tabular-nums text-white shadow"
      style="
        right: max(1rem, env(safe-area-inset-right));
        bottom: max(1.75rem, calc(env(safe-area-inset-bottom) + 0.75rem));
      "
    >
      Seite {{ currentPage }} von {{ pageCount }}
    </div>
    <p
      v-if="shareError"
      class="absolute right-4 max-w-[calc(100%-6rem)] rounded-md bg-gray-900/90 px-3 py-2 text-sm text-white shadow"
      style="bottom: max(4.5rem, calc(env(safe-area-inset-bottom) + 3.5rem))"
    >
      {{ shareError }}
    </p>
  </div>
</template>

<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import useFileViewer, {
  usesFileViewer,
  viewerLink,
} from "../../composables/useFileViewer";

/**
 * Full screen viewer for pdfs and images in the home screen app on iOS, with a share button that
 * opens the share sheet (print, save to files, send). See useFileViewer. Mounted once next to the
 * app (app.ts); links to pdfs open here by themselves.
 */

const { current, openUrl, close } = useFileViewer();

const scroller = ref<HTMLElement | null>(null);
const content = ref<HTMLElement | null>(null);
const pages = ref<HTMLElement | null>(null);

const rendering = ref(false);
const renderError = ref<string | null>(null);
const shareError = ref<string | null>(null);
const error = computed(() => current.value?.error ?? renderError.value);

const isImage = computed(
  () =>
    !!current.value?.files.length &&
    current.value.files.every((file) => file.type.startsWith("image/")),
);
const imageUrls = ref<string[]>([]);

// zoom: 1 = page as wide as the screen
const MAX_ZOOM = 5;
const zoom = ref(1);
const baseWidth = ref(0);
const pageWidth = computed(() => Math.round(baseWidth.value * zoom.value));

// ---------------------------------------------------------------- pages

const pageCount = ref(0);
const currentPage = ref(1);

/**
 * The page in the middle of the screen counts as the current one.
 */
const updateCurrentPage = () => {
  const element = scroller.value;
  // pages of a pdf (canvas) or images
  const shown = content.value?.querySelectorAll("canvas, img");
  if (!element || !shown?.length) {
    return;
  }
  const middle = element.getBoundingClientRect().top + element.clientHeight / 2;
  let page = 1;
  shown.forEach((entry, index) => {
    if (entry.getBoundingClientRect().top <= middle) {
      page = index + 1;
    }
  });
  currentPage.value = page;
};

// ---------------------------------------------------------------- pdf

type PdfLoading =
  import("pdfjs-dist/types/src/display/api").PDFDocumentLoadingTask;
type PdfDocument = import("pdfjs-dist/types/src/display/api").PDFDocumentProxy;
let loadingTask: PdfLoading | null = null;
let pdf: PdfDocument | null = null;
let renderRun = 0;

/**
 * pdf.js is loaded only when the viewer needs it (about 1 MB). The legacy build also runs on
 * older iOS versions.
 */
const loadPdfJs = async () => {
  const [pdfjs, worker] = await Promise.all([
    import("pdfjs-dist/legacy/build/pdf.mjs"),
    import("pdfjs-dist/legacy/build/pdf.worker.min.mjs?url"),
  ]);
  pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
  return pdfjs;
};

const clearPages = () => {
  for (const canvas of pages.value?.querySelectorAll("canvas") ?? []) {
    // frees the memory of the canvas at once (Safari keeps it otherwise)
    canvas.width = 0;
    canvas.height = 0;
  }
  pages.value?.replaceChildren();
};

/**
 * Draw all pages in the current width. Sharper than the screen needs (pixel ratio), but at most
 * 2400 pixels wide per page: iOS limits the memory of all canvases together.
 */
const renderPages = async () => {
  if (!pdf || !pages.value) {
    return;
  }
  const run = ++renderRun;
  rendering.value = true;
  const ratio = Math.min(window.devicePixelRatio || 1, 2);
  const canvases: HTMLCanvasElement[] = [];
  try {
    for (let number = 1; number <= pdf.numPages; number++) {
      const page = await pdf.getPage(number);
      if (run !== renderRun) {
        return;
      }
      const width = pageWidth.value;
      const natural = page.getViewport({ scale: 1 });
      const pixelWidth = Math.min(width * ratio, 2400);
      const viewport = page.getViewport({
        scale: pixelWidth / natural.width,
      });
      const canvas = document.createElement("canvas");
      canvas.width = Math.floor(viewport.width);
      canvas.height = Math.floor(viewport.height);
      canvas.style.width = `${width}px`;
      canvas.style.height = `${(width * natural.height) / natural.width}px`;
      canvas.className = "block bg-white shadow-lg";
      await page.render({ canvas, viewport }).promise;
      if (run !== renderRun) {
        canvas.width = 0;
        canvas.height = 0;
        return;
      }
      canvases.push(canvas);
    }
    clearPages();
    pages.value?.append(...canvases);
    updateCurrentPage();
  } catch (exception) {
    if (run === renderRun) {
      renderError.value =
        "Die Vorschau ging nicht. Über „Teilen“ lässt sich die Datei trotzdem drucken oder öffnen.";
      console.error(exception);
    }
  } finally {
    if (run === renderRun) {
      rendering.value = false;
    }
  }
};

const reset = () => {
  renderRun++;
  // ends the worker and frees the document
  loadingTask?.destroy();
  loadingTask = null;
  pdf = null;
  clearPages();
  imageUrls.value.forEach((url) => URL.revokeObjectURL(url));
  imageUrls.value = [];
  zoom.value = 1;
  pageCount.value = 0;
  currentPage.value = 1;
  rendering.value = false;
  renderError.value = null;
  shareError.value = null;
};

const show = async (files: File[]) => {
  await nextTick();
  baseWidth.value = Math.max((scroller.value?.clientWidth ?? 320) - 24, 200);
  if (files.every((file) => file.type.startsWith("image/"))) {
    imageUrls.value = files.map((file) => URL.createObjectURL(file));
    pageCount.value = files.length;
    return;
  }
  // a pdf comes alone
  const file = files[0];
  rendering.value = true;
  try {
    const pdfjs = await loadPdfJs();
    const task = pdfjs.getDocument({ data: await file.arrayBuffer() });
    // another file was opened meanwhile
    if (current.value?.files[0] !== file) {
      task.destroy();
      return;
    }
    loadingTask = task;
    pdf = await task.promise;
    pageCount.value = pdf.numPages;
  } catch (exception) {
    // closed or replaced while loading
    if (current.value?.files[0] !== file) {
      return;
    }
    rendering.value = false;
    renderError.value =
      "Die Vorschau ging nicht. Über „Teilen“ lässt sich die Datei trotzdem drucken oder öffnen.";
    console.error(exception);
    return;
  }
  await renderPages();
};

watch(
  () => current.value?.files,
  (files, previous) => {
    if (files === previous) {
      return;
    }
    reset();
    if (files?.length) {
      show(files);
    }
  },
);

// ---------------------------------------------------------------- zoom

const gesture = ref<{ scale: number; originX: number; originY: number } | null>(
  null,
);
const gestureStyle = computed(() =>
  gesture.value
    ? {
        transform: `scale(${gesture.value.scale})`,
        transformOrigin: `${gesture.value.originX}px ${gesture.value.originY}px`,
      }
    : {},
);
let gestureCenter = { x: 0, y: 0 };

type GestureEvent = UIEvent & {
  scale: number;
  clientX: number;
  clientY: number;
};

/**
 * Pinch on iOS (gesture events of Safari): while pinching the pages are scaled with css, at the end
 * they are drawn again in the new size, the point under the fingers stays in place.
 */
const gestureStart = (event: Event) => {
  const { clientX, clientY } = event as GestureEvent;
  const box = scroller.value!.getBoundingClientRect();
  gestureCenter = { x: clientX - box.left, y: clientY - box.top };
  gesture.value = {
    scale: 1,
    originX: scroller.value!.scrollLeft + gestureCenter.x,
    originY: scroller.value!.scrollTop + gestureCenter.y,
  };
};

const gestureChange = (event: Event) => {
  if (!gesture.value) {
    return;
  }
  const scale = (event as GestureEvent).scale;
  // stays between 1 and the largest zoom
  gesture.value.scale = Math.min(
    Math.max(scale, 1 / zoom.value),
    MAX_ZOOM / zoom.value,
  );
};

const gestureEnd = () => {
  if (!gesture.value) {
    return;
  }
  const factor = gesture.value.scale;
  gesture.value = null;
  setZoom(zoom.value * factor, gestureCenter);
};

const toggleZoom = (event: MouseEvent) => {
  const box = scroller.value!.getBoundingClientRect();
  setZoom(zoom.value > 1 ? 1 : 2.5, {
    x: event.clientX - box.left,
    y: event.clientY - box.top,
  });
};

const setZoom = async (value: number, center: { x: number; y: number }) => {
  const next = Math.min(Math.max(value, 1), MAX_ZOOM);
  if (Math.abs(next - zoom.value) < 0.01 || !scroller.value) {
    return;
  }
  const element = scroller.value;
  const factor = next / zoom.value;
  const pointX = element.scrollLeft + center.x;
  const pointY = element.scrollTop + center.y;
  zoom.value = next;

  // pages in the new size at once (stretched), sharp again after drawing
  for (const canvas of pages.value?.querySelectorAll("canvas") ?? []) {
    canvas.style.width = `${parseFloat(canvas.style.width) * factor}px`;
    canvas.style.height = `${parseFloat(canvas.style.height) * factor}px`;
  }
  await nextTick();
  element.scrollLeft = pointX * factor - center.x;
  element.scrollTop = pointY * factor - center.y;
  updateCurrentPage();
  scheduleRender();
};

let renderTimer: ReturnType<typeof setTimeout> | undefined;
const scheduleRender = () => {
  clearTimeout(renderTimer);
  renderTimer = setTimeout(renderPages, 250);
};

// ---------------------------------------------------------------- share

/**
 * Share sheet of the system with the files. They are already loaded, so this still counts as the
 * tap of the user (Safari and Bluefy do not allow share() after a fetch).
 */
const share = async () => {
  const files = current.value?.files ?? [];
  if (!files.length) {
    return;
  }
  shareError.value = null;
  if (navigator.canShare?.({ files })) {
    try {
      await navigator.share({ files, title: current.value!.name });
    } catch (exception) {
      // closed by the user
      if (!(
        exception instanceof DOMException && exception.name === "AbortError"
      )) {
        shareError.value = "Teilen ging nicht. Bitte noch einmal versuchen.";
      }
    }
    return;
  }
  shareError.value =
    "Dieses Gerät kann die Datei nicht teilen. Öffne Stocks in Safari, um sie zu drucken.";
};

// ---------------------------------------------------------------- links and keys

/**
 * Links to pdfs (and marked links to images) open in the viewer instead of the browser (only where
 * usesFileViewer: home screen app and Bluefy on iOS).
 */
const interceptLink = (event: MouseEvent) => {
  if (
    event.defaultPrevented ||
    event.button !== 0 ||
    event.metaKey ||
    event.ctrlKey ||
    !usesFileViewer()
  ) {
    return;
  }
  const url = viewerLink(event.target);
  if (url) {
    event.preventDefault();
    openUrl(url);
  }
};

const onKey = (event: KeyboardEvent) => {
  if (event.key === "Escape" && current.value) {
    close();
  }
};

// the page behind does not scroll while the viewer is open
watch(
  () => current.value !== null,
  (open) => {
    document.documentElement.style.overflow = open ? "hidden" : "";
  },
);

onMounted(() => {
  document.addEventListener("click", interceptLink, true);
  document.addEventListener("keydown", onKey);
});

onBeforeUnmount(() => {
  document.removeEventListener("click", interceptLink, true);
  document.removeEventListener("keydown", onKey);
  clearTimeout(renderTimer);
  reset();
});
</script>
