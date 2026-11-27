<template>
  <div class="space-y-3 rounded-lg bg-surface-muted p-3 sm:col-span-2">
    <div class="flex items-end gap-2">
      <UiInput
        v-model="code"
        class="min-w-0 flex-1"
        label="EAN der Verpackung"
        inputmode="numeric"
        placeholder="Barcode scannen oder eintippen"
        :error="error ?? codeError"
        @keydown.enter.prevent="lookup()"
      />
      <UiButton
        v-if="enabled"
        variant="secondary"
        icon="magnifying-glass"
        :disabled="loading || !code.trim()"
        @click="lookup()"
      >
        <span class="sr-only sm:not-sr-only">Suchen</span>
      </UiButton>
      <UiButton
        v-if="enabled"
        variant="secondary"
        icon="barcode"
        :disabled="loading"
        @click="cameraOpen = !cameraOpen"
      >
        <span class="sr-only sm:not-sr-only">Scannen</span>
      </UiButton>
    </div>

    <InventoryCameraScanner
      v-if="cameraOpen"
      autostart
      :formats="['ean_13', 'ean_8', 'upc_a', 'upc_e']"
      class="aspect-[4/3] w-full sm:aspect-video"
      @scan="scanned"
      @stopped="cameraOpen = false"
    />

    <p v-if="loading" class="text-sm text-ink-muted">Suche Produktdaten …</p>

    <template v-if="result && !loading">
      <!-- items with this barcode exist already -->
      <UiAlert v-if="result.existing.length" type="info">
        Gibt es schon:
        <span
          v-for="(entry, index) in result.existing"
          :key="entry.id"
          class="whitespace-nowrap"
          ><UiLink :href="entry.href" class="font-mono">{{
            entry.number
          }}</UiLink>
          {{ entry.name }}{{ index < result.existing.length - 1 ? ", " : "" }}
        </span>
        . Trotzdem neu anlegen geht.
      </UiAlert>

      <p v-if="!result.found" class="text-sm text-ink-muted">
        Nichts gefunden{{
          result.unavailable.length
            ? ` (nicht erreichbar: ${result.unavailable.join(", ")})`
            : ""
        }}. Die EAN wird trotzdem gespeichert.
      </p>

      <div v-else class="space-y-3">
        <div v-if="result.name" class="space-y-1">
          <UiCheckbox v-model="takeName" label="Name übernehmen" />
          <UiInput v-if="takeName" v-model="name" label="Name" class="pl-7" />
        </div>
        <div v-if="result.description" class="space-y-1">
          <UiCheckbox
            v-model="takeDescription"
            label="Beschreibung übernehmen"
          />
          <UiInput
            v-if="takeDescription"
            v-model="description"
            label="Beschreibung"
            class="pl-7"
          />
        </div>

        <div v-if="result.images.length">
          <p class="text-sm font-medium">Bilder übernehmen (antippen)</p>
          <div class="mt-2 grid grid-cols-4 gap-2 sm:grid-cols-6">
            <button
              v-for="image in result.images"
              :key="image.index"
              type="button"
              :class="[
                'relative aspect-square overflow-hidden rounded-md bg-white ring-2',
                chosen.includes(image.index) ? 'ring-accent' : 'ring-line',
              ]"
              :aria-pressed="chosen.includes(image.index)"
              :aria-label="`Bild ${image.index + 1} von ${image.source}`"
              @click="toggle(image.index)"
            >
              <img
                :src="imageUrl(image.index)"
                alt=""
                loading="lazy"
                class="h-full w-full object-contain"
                @error="broken.push(image.index)"
              />
              <span
                v-if="chosen.includes(image.index)"
                class="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-accent text-xs text-white"
              >
                <FontAwesomeIcon :icon="['fas', 'check']" />
              </span>
            </button>
          </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
          <p v-if="applyError" class="flex-1 text-sm text-red-600">
            {{ applyError }}
          </p>
          <UiButton variant="ghost" size="sm" @click="result = null">
            Verwerfen
          </UiButton>
          <UiButton size="sm" icon="check" :disabled="applying" @click="apply">
            Übernehmen
          </UiButton>
        </div>

        <!-- credit as the licences ask for it (ODbL, CC BY-SA), kept small -->
        <p class="text-xs text-ink-muted">
          Daten:
          <template v-for="(source, index) in result.sources" :key="source.name"
            ><a
              v-if="source.url"
              :href="source.url"
              target="_blank"
              rel="noopener"
              class="underline"
              >{{
                source.license === "ODbL"
                  ? `© ${source.name} contributors (ODbL)`
                  : source.name
              }}</a
            ><template v-else>{{ source.name }}</template
            >{{ index < result.sources.length - 1 ? " · " : "" }}</template
          >
        </p>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref } from "vue";

export type ProductSuggestion = {
  name: string | null;
  description: string | null;
  // images with the reference the server keeps their origin under ("<code>:<index>")
  images: { file: File; ref: string }[];
};

type LookupResult = {
  code: string;
  found: boolean;
  name: string | null;
  description: string | null;
  images: { index: number; source: string; license: string | null }[];
  sources: { name: string; url: string | null; license: string | null }[];
  unavailable: string[];
  existing: { id: number; number: string; name: string; href: string }[];
};

/**
 * Barcode of the packaging in the guided capture: scan or type the EAN, then take name,
 * description and images from product databases (App\Support\Products\ProductLookup). Everything
 * is a suggestion: each part can be left out and the texts changed. Without the setting
 * "Produktdaten per Barcode" only the field is shown.
 */
const props = withDefaults(
  defineProps<{ enabled: boolean; codeError?: string | null }>(),
  { codeError: null },
);
const emit = defineEmits<{ apply: [suggestion: ProductSuggestion] }>();

const code = defineModel<string>("code", { required: true });

const { url } = useSurface();
const { compressImage } = useImageCompression();

const cameraOpen = ref(false);
const loading = ref(false);
const error = ref<string | null>(null);
const result = ref<LookupResult | null>(null);
const takeName = ref(true);
const name = ref("");
const takeDescription = ref(true);
const description = ref("");
const chosen = ref<number[]>([]);
const broken = ref<number[]>([]);

let format = "";
const scanned = (scannedCode: string, scannedFormat: string) => {
  code.value = scannedCode;
  format = scannedFormat;
  cameraOpen.value = false;
  lookup();
};

const lookup = async () => {
  if (!props.enabled || !code.value.trim()) {
    return;
  }
  // a label of Stocks is no packaging
  if (/[a-z]/i.test(code.value)) {
    error.value =
      "Das ist ein Etikett von Stocks, kein Barcode einer Verpackung.";
    return;
  }
  loading.value = true;
  error.value = null;
  result.value = null;
  const query = new URLSearchParams({ code: code.value.trim(), format });
  format = "";
  try {
    const response = await fetch(url(`/inventory/capture/product?${query}`), {
      headers: { Accept: "application/json" },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      error.value =
        data.errors?.code?.[0] ??
        data.message ??
        "Die Suche ging gerade nicht. Versuch es gleich noch einmal.";
      return;
    }
    result.value = data;
    // the stored form, e.g. a UPC with its leading 0
    code.value = data.code;
    name.value = data.name ?? "";
    description.value = data.description ?? "";
    takeName.value = true;
    takeDescription.value = true;
    chosen.value = [];
    broken.value = [];
  } catch {
    error.value = "Keine Verbindung. Versuch es gleich noch einmal.";
  } finally {
    loading.value = false;
  }
};

const imageUrl = (index: number) =>
  url(`/inventory/capture/product/${result.value!.code}/images/${index}`);

const toggle = (index: number) => {
  chosen.value = chosen.value.includes(index)
    ? chosen.value.filter((entry) => entry !== index)
    : [...chosen.value, index];
};

// the chosen images come from Stocks (the server downloads them) and are scaled like own photos
const applying = ref(false);
const applyError = ref<string | null>(null);
const apply = async () => {
  if (!result.value) {
    return;
  }
  applying.value = true;
  applyError.value = null;
  const images: ProductSuggestion["images"] = [];
  for (const index of chosen.value.filter((i) => !broken.value.includes(i))) {
    const response = await fetch(imageUrl(index)).catch(() => null);
    if (!response?.ok) {
      applyError.value = "Ein Bild ließ sich nicht laden und fehlt.";
      continue;
    }
    const file = new File(
      [await response.blob()],
      `${result.value.code}-${index + 1}.jpg`,
      { type: "image/jpeg" },
    );
    images.push({
      file: await compressImage(file),
      ref: `${result.value.code}:${index}`,
    });
  }
  emit("apply", {
    name: takeName.value && name.value.trim() ? name.value.trim() : null,
    description:
      takeDescription.value && description.value.trim()
        ? description.value.trim()
        : null,
    images,
  });
  applying.value = false;
  if (!applyError.value) {
    result.value = null;
  }
};
</script>
