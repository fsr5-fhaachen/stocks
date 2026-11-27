<template>
  <UiPageHeader
    title="Erfassen"
    description="Neue Gegenstände Schritt für Schritt anlegen und gleich das Etikett drucken."
  >
    <UiButton variant="ghost" icon="arrow-left" href="/inventory/items">
      Zur Liste
    </UiButton>
  </UiPageHeader>

  <!-- the round: everything captured in this tab -->
  <template v-if="showRound">
    <UiCard title="Diese Runde" flush>
      <ul v-if="round.length" class="divide-y divide-line">
        <li
          v-for="entry in round"
          :key="entry.id"
          class="flex items-center gap-3 px-4 py-3 text-sm sm:px-6"
        >
          <span class="min-w-0 flex-1">
            <UiLink :href="entry.href" class="font-medium">{{
              entry.name
            }}</UiLink>
            <span class="ml-2 font-mono text-ink-muted">{{
              entry.number
            }}</span>
            <span class="block text-ink-muted">
              <InventoryPlaceLink :place="entry.place" empty="ohne Ort" />
            </span>
          </span>
          <UiBadge :variant="entry.printed ? 'success' : 'warning'">
            {{ entry.printed ? "etikettiert" : "ohne Etikett" }}
          </UiBadge>
        </li>
      </ul>
      <UiEmptyState v-else icon="plus" title="Noch nichts erfasst" />
    </UiCard>
    <div class="mt-6 flex flex-wrap justify-end gap-3">
      <UiButton
        v-if="can('labels.print') && unprintedInRound.length"
        variant="secondary"
        icon="print"
        :href="`/labels/print?items=${unprintedInRound.join(',')}`"
      >
        {{ unprintedInRound.length }} Etiketten drucken
      </UiButton>
      <UiButton variant="secondary" @click="showRound = false">
        Weiter erfassen
      </UiButton>
      <UiButton icon="check" @click="newRound">Neue Runde</UiButton>
    </div>
  </template>

  <!-- after "Erstellen": numbers, printing, next item -->
  <template v-else-if="resultIds">
    <UiCard
      :title="
        resultItems.length === 1
          ? `${resultItems[0].number} angelegt`
          : `${resultItems.length} Gegenstände angelegt`
      "
    >
      <ul class="divide-y divide-line">
        <li
          v-for="entry in resultItems"
          :key="entry.id"
          class="flex items-center gap-3 py-2 text-sm"
        >
          <span class="min-w-0 flex-1">
            <span class="font-mono font-semibold">{{ entry.number }}</span>
            <span class="ml-2">{{ entry.name }}</span>
            <span class="block text-ink-muted">
              <InventoryPlaceLink :place="entry.place" empty="ohne Ort" />
            </span>
          </span>
          <UiBadge :variant="entry.printed ? 'success' : 'warning'">
            {{ entry.printed ? "etikettiert" : "ohne Etikett" }}
          </UiBadge>
        </li>
      </ul>

      <!-- printing what is not printed yet -->
      <div
        v-if="result && result.labels.length && unprintedLabels.length"
        class="mt-4 space-y-3 border-t border-line pt-4"
      >
        <template v-if="printer?.driver === 'niimbot'">
          <template v-if="connections.length">
            <UiButton
              v-for="connection in connections"
              v-show="!connected"
              :key="connection"
              class="w-full"
              :disabled="busy"
              @click="connectAndPrint(connection)"
            >
              {{
                connection === "bluetooth"
                  ? "Per Bluetooth verbinden und drucken"
                  : "Per USB verbinden und drucken"
              }}
            </UiButton>
            <UiButton
              v-if="connected"
              class="w-full"
              icon="print"
              :disabled="busy || !baseUrlConfirmed"
              @click="printDirect"
            >
              {{ anyPrinted ? "Rest drucken" : "Drucken" }}
            </UiButton>
          </template>
          <LabelsDirectPrintHint v-else />
          <UiButton
            :variant="connections.length ? 'secondary' : 'primary'"
            class="w-full"
            icon="download"
            :disabled="busy || !baseUrlConfirmed"
            @click="downloadPng"
          >
            {{
              unprintedLabels.length > 1
                ? "Bilder für die Niimbot-App"
                : "Bild für die Niimbot-App"
            }}
          </UiButton>
        </template>
        <UiButton
          v-else-if="printer"
          class="w-full"
          icon="print"
          :disabled="busy || !baseUrlConfirmed"
          @click="printOnPaper"
        >
          Drucken
        </UiButton>

        <div v-if="progress" class="space-y-2">
          <div class="h-2 overflow-hidden rounded-full bg-surface-muted">
            <div
              class="h-full bg-accent transition-all"
              :style="{ width: `${(progress.done / progress.total) * 100}%` }"
            />
          </div>
          <p class="flex items-center justify-between text-sm text-ink-muted">
            {{ progress.done }} von {{ progress.total }} gedruckt
            <button type="button" class="font-medium underline" @click="cancel">
              Abbrechen
            </button>
          </p>
        </div>
        <p v-if="printError" class="text-sm text-red-600">{{ printError }}</p>
        <p v-if="printed" class="text-sm text-green-700 dark:text-green-400">
          {{ printed }}
        </p>

        <!-- paper and images: only the person knows whether the label is on the item -->
        <UiCheckbox
          v-model="stuck"
          label="Etikett wurde aufgeklebt"
          description="Ohne Häkchen gilt der Gegenstand als „noch ohne Etikett“."
        />
      </div>

      <!-- after a reload the labels are printed from the print page -->
      <div
        v-else-if="!result && can('labels.print') && unprintedResult.length"
        class="mt-4 border-t border-line pt-4"
      >
        <UiButton
          variant="secondary"
          icon="print"
          :href="`/labels/print?items=${unprintedResult.join(',')}`"
        >
          Etikett drucken
        </UiButton>
      </div>
    </UiCard>

    <div class="mt-6 flex flex-wrap justify-end gap-3">
      <UiButton variant="secondary" @click="showRound = true">
        Fertig
      </UiButton>
      <UiButton
        variant="secondary"
        :href="
          resultItems.length === 1 ? resultItems[0].href : '/inventory/items'
        "
      >
        {{ resultItems.length === 1 ? "Zum Gegenstand" : "Zur Liste" }}
      </UiButton>
      <UiButton icon="plus" @click="nextItem"> Nächster Gegenstand </UiButton>
    </div>
  </template>

  <!-- the steps -->
  <template v-else>
    <UiSteps
      :steps="stepLabels"
      :current="index"
      :reachable="reached"
      @select="open"
    />

    <UiAlert v-if="photosLost && step !== 'photos'" type="info" class="mb-4">
      Die Seite wurde neu geladen, die gewählten Fotos sind dabei verloren
      gegangen. Unter „Bilder“ neu wählen.
    </UiAlert>
    <UiAlert v-if="otherErrors.length" type="error" class="mb-4">
      {{ otherErrors.join(" ") }}
    </UiAlert>

    <UiCard v-if="step === 'data'">
      <!-- room for the barcode scan of the packaging (next round) -->
      <InventoryItemFields
        :form="form"
        :errors="errors"
        :categories="categories"
        :kinds="kinds"
        :units="units"
        :conditions="conditions"
        collapsible
      >
        <template #kind>
          <UiInput
            v-if="form.kind === 'single'"
            v-model="form.count"
            label="Anzahl gleicher Stücke"
            type="number"
            inputmode="numeric"
            :hint="`Für Dinge, die man einzeln wiedererkennen muss; jedes bekommt eine Nummer und ein Etikett. Sonst „Menge“ wählen. Höchstens ${maxCount}.`"
            :error="errors.count"
          />
        </template>
        <template #more>
          <UiInput
            v-if="!printing"
            v-model="form.label_hint"
            label="Hinweis fürs Etikett"
            placeholder="z. B. Achtung, zerbrechlich"
            :error="errors.label_hint"
          />
          <div class="space-y-4 sm:col-span-2">
            <UiCheckbox
              v-model="form.is_container"
              label="Ist eine Box"
              description="Andere Gegenstände können darin liegen und ziehen mit ihr um."
            />
            <UiCheckbox
              v-if="form.is_container"
              v-model="form.public_contents"
              label="Inhalt öffentlich zeigen"
              description="Später zeigt der QR-Code der Box ihre Inhaltsliste ohne Anmeldung."
            />
          </div>
        </template>
      </InventoryItemFields>
    </UiCard>

    <UiCard
      v-else-if="step === 'photos'"
      title="Bilder"
      :description="`Optional, bis zu ${maxPhotos} Fotos. Das erste wird das Vorschaubild${countNumber > 1 ? ' und gilt für alle Stücke' : ''}.`"
    >
      <UiPhotoPicker v-model="photos" :max="maxPhotos" :error="photoError" />
    </UiCard>

    <UiCard v-else-if="step === 'place'" key="place" title="Ort">
      <InventoryCapturePlaceStep
        v-model:code="form.place_code"
        v-model:place="place"
        kind="place"
        :error="fieldError('place_code', 'place')"
      >
        <UiCheckbox
          v-model="form.home_same"
          label="Auch als Stammplatz"
          description="Hierhin gehört der Gegenstand auch zurück. Ohne Häkchen kommt ein eigener Schritt für den Stammplatz."
        />
      </InventoryCapturePlaceStep>
    </UiCard>

    <UiCard v-else-if="step === 'home'" key="home" title="Stammplatz">
      <InventoryCapturePlaceStep
        v-model:code="form.home_code"
        v-model:place="home"
        kind="home"
        :error="fieldError('home_code', 'home')"
      />
    </UiCard>

    <template v-else-if="step === 'label' && printing">
      <UiAlert v-if="!printing.baseUrlConfirmed" type="warning" class="mb-4">
        Die Codes führen auf
        <strong class="font-mono">{{ printing.baseUrl }}</strong>. Ändert sich
        diese Adresse später, öffnet die Handykamera die Etiketten nicht mehr.
        <button
          type="button"
          class="ml-1 font-bold underline"
          @click="confirmAddress"
        >
          Adresse stimmt, weiter
        </button>
      </UiAlert>
      <UiEmptyState
        v-if="!design"
        icon="tag"
        :title="`Keine Vorlage für ${target === 'box' ? 'Boxen' : 'Gegenstände'}`"
        description="Ohne Vorlage gibt es kein Etikett; der Gegenstand wird trotzdem angelegt."
      />
      <template v-else-if="previewData">
        <!-- the editor only on request: on phones it takes the whole screen and moves elements
             while scrolling -->
        <template v-if="editing">
          <LabelsEditor
            :key="target"
            v-model="designs[target]"
            :data="previewData"
            :variables="printing.variables[target] ?? []"
            :logo-url="printing.logoUrl"
            :printer="printer"
          />
          <div class="mt-3 flex justify-end">
            <UiButton
              variant="secondary"
              size="sm"
              icon="check"
              @click="editing = false"
            >
              Fertig angepasst
            </UiButton>
          </div>
        </template>
        <template v-else>
          <LabelsPreview
            :template="design"
            :data="previewData"
            :logo-url="printing.logoUrl"
            :printer="printer"
          />
          <div class="mt-2 flex justify-end">
            <UiButton
              variant="ghost"
              size="sm"
              icon="pen"
              @click="editing = true"
            >
              Etikett anpassen
            </UiButton>
          </div>
        </template>
        <UiCard title="Druck" class="mt-4">
          <div class="space-y-4">
            <UiSelect
              v-if="printing.printers.length"
              v-model="printerId"
              label="Drucker"
              :options="
                printing.printers.map((entry) => ({
                  value: entry.id,
                  label: entry.name,
                }))
              "
              :placeholder="null"
            />
            <p v-else class="text-sm text-ink-muted">
              Es ist kein Drucker angelegt.
              <UiLink v-if="can('labels.manage')" href="/labels/printers"
                >Drucker anlegen</UiLink
              >
            </p>
            <UiSelect
              :model-value="templateIds[target]"
              label="Vorlage"
              :options="templatesOf(target)"
              :placeholder="null"
              @update:model-value="chooseTemplate(target, Number($event))"
            />
            <UiInput
              v-model="form.label_hint"
              label="Hinweis fürs Etikett"
              placeholder="z. B. Achtung, zerbrechlich"
              hint="Wird beim Gegenstand gespeichert. Leer: Das Element bleibt weg."
              :error="errors.label_hint"
            />
            <UiInput
              v-model="copies"
              label="Kopien je Stück"
              type="number"
              inputmode="numeric"
            />

            <!-- connecting needs its own tap (the browser asks for the device) -->
            <div
              v-if="printer?.driver === 'niimbot'"
              class="space-y-2 border-t border-line pt-4"
            >
              <template v-if="connections.length">
                <UiButton
                  v-for="connection in connections"
                  v-show="!connected"
                  :key="connection"
                  variant="secondary"
                  class="w-full"
                  :disabled="busy"
                  @click="connectPrinter(connection)"
                >
                  {{
                    connection === "bluetooth"
                      ? "Per Bluetooth verbinden"
                      : "Per USB verbinden"
                  }}
                </UiButton>
                <p v-if="connected" class="text-sm text-ink-muted">
                  Verbunden{{ deviceName ? ` mit ${deviceName}` : "" }}.
                </p>
                <p v-else class="text-xs text-ink-muted">
                  Ohne Verbindung wird erst angelegt, gedruckt wird danach.
                </p>
              </template>
              <LabelsDirectPrintHint v-else />
              <p v-if="printError" class="text-sm text-red-600">
                {{ printError }}
              </p>
            </div>
          </div>
        </UiCard>
      </template>
    </template>

    <div v-else-if="step === 'summary'" class="space-y-4">
      <UiAlert v-if="!form.place_code.trim()" type="warning">
        Kein Ort angegeben: Der Gegenstand landet unter „Ohne Platz“.
      </UiAlert>

      <UiCard title="Daten">
        <template #actions>
          <UiButton variant="ghost" size="sm" @click="open(0)">Ändern</UiButton>
        </template>
        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-ink-muted">Name</dt>
            <dd class="font-medium">{{ form.name }}</dd>
          </div>
          <div>
            <dt class="text-ink-muted">Art</dt>
            <dd>
              {{
                form.kind === "bulk"
                  ? `Menge: ${form.quantity} ${form.unit ?? ""}`
                  : countNumber > 1
                    ? `${countNumber} gleiche Einzelstücke`
                    : "Einzelstück"
              }}{{ form.is_container ? " · Box" : "" }}
            </dd>
          </div>
          <div>
            <dt class="text-ink-muted">Kategorie</dt>
            <dd>{{ labelOf(categories, form.category_id) ?? "keine" }}</dd>
          </div>
          <div>
            <dt class="text-ink-muted">Zustand</dt>
            <dd>{{ labelOf(conditions, form.condition_id) }}</dd>
          </div>
          <div v-if="form.description" class="sm:col-span-2">
            <dt class="text-ink-muted">Beschreibung</dt>
            <dd class="whitespace-pre-line">{{ form.description }}</dd>
          </div>
          <div v-if="form.notes" class="sm:col-span-2">
            <dt class="text-ink-muted">Notiz</dt>
            <dd class="whitespace-pre-line">{{ form.notes }}</dd>
          </div>
        </dl>
      </UiCard>

      <UiCard title="Bilder">
        <template #actions>
          <UiButton variant="ghost" size="sm" @click="open(1)">Ändern</UiButton>
        </template>
        <p class="text-sm">
          {{
            photos.length
              ? `${photos.length} ${photos.length === 1 ? "Foto" : "Fotos"}`
              : "keine"
          }}
        </p>
      </UiCard>

      <UiCard title="Ort">
        <template #actions>
          <UiButton variant="ghost" size="sm" @click="open(2)">Ändern</UiButton>
        </template>
        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div>
            <dt class="text-ink-muted">Liegt in</dt>
            <dd>
              <InventoryPlaceLink v-if="place" :place="place" /><span
                v-else
                class="font-mono"
                >{{ form.place_code || "—" }}</span
              >
            </dd>
          </div>
          <div>
            <dt class="text-ink-muted">Stammplatz</dt>
            <dd>
              <template v-if="form.home_same">{{
                form.place_code ? "wie der Ort" : "keiner"
              }}</template>
              <InventoryPlaceLink v-else-if="home" :place="home" />
              <span v-else class="font-mono">{{
                form.home_code || "keiner"
              }}</span>
            </dd>
          </div>
        </dl>
      </UiCard>

      <UiCard v-if="printing" title="Etikett">
        <template #actions>
          <UiButton
            variant="ghost"
            size="sm"
            @click="open(steps.indexOf('label'))"
            >Ändern</UiButton
          >
        </template>
        <LabelsPreview
          v-if="design && previewData"
          :template="design"
          :data="previewData"
          :logo-url="printing.logoUrl"
          :printer="printer"
        />
        <p class="mt-3 text-sm text-ink-muted">
          {{ printSummary }}
        </p>
      </UiCard>

      <p v-if="countNumber > 1" class="text-sm font-medium">
        Legt {{ countNumber }} Gegenstände an{{
          willPrint ? `, druckt ${countNumber * copyCount()} Etiketten` : ""
        }}.
      </p>
      <p v-if="createError" class="text-sm text-red-600">{{ createError }}</p>
    </div>

    <!-- back and on, always at the same place; on phones above the bar at the bottom -->
    <div
      class="sticky bottom-[calc(var(--bottom-bar)+env(safe-area-inset-bottom)+0.5rem)] z-10 mt-6 flex items-center justify-between gap-2 rounded-lg bg-surface/95 p-2 shadow ring-1 ring-line backdrop-blur lg:bottom-4"
    >
      <UiButton
        v-if="index > 0"
        variant="secondary"
        icon="chevron-left"
        @click="open(index - 1)"
      >
        Zurück
      </UiButton>
      <span v-else />
      <UiButton
        v-if="step !== 'summary'"
        icon="arrow-right"
        :disabled="!valid || loading"
        @click="open(index + 1)"
      >
        <template v-if="step === 'photos' && !photos.length"
          >Überspringen</template
        >
        <template v-else
          >Weiter<span class="hidden sm:inline"
            >: {{ stepLabels[index + 1] }}</span
          ></template
        >
      </UiButton>
      <UiButton
        v-else
        :icon="willPrint ? 'print' : 'check'"
        :disabled="creating || busy"
        @click="createAndPrint"
      >
        {{ willPrint ? "Erstellen und drucken" : "Erstellen" }}
      </UiButton>
    </div>
  </template>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import type { PrintLabel } from "../../labels/drivers";
import type { CaptureForm } from "../../composables/useCapture";

/**
 * Guided capture of new items: data, photos, place (and home), label, summary, then one tap
 * creates and prints. Several equal single pieces are created at once. Only a direct print or the
 * tick "Etikett wurde aufgeklebt" counts as labelled (documentation/INVENTORY.md).
 */
const props = defineProps<{
  categories: Option[];
  kinds: Option[];
  units: Option[];
  conditions: Option[];
  maxPhotos: number;
  maxCount: number;
  // only with the right to print labels
  printing: {
    templates: LabelTemplate[];
    printers: PrinterData[];
    variables: Partial<Record<LabelTargetName, string[]>>;
    baseUrl: string;
    baseUrlConfirmed: boolean;
    logoUrl: string | null;
  } | null;
}>();

const { can } = usePermissions();
const { url } = useSurface();

const defaults = (): CaptureForm => ({
  name: "",
  description: "",
  category_id: null,
  kind: "single",
  quantity: 1,
  // the units come from the settings; the first one is "Stück"
  unit: props.units[0] ? String(props.units[0].value) : null,
  // "gut" if it exists, otherwise the first condition
  condition_id:
    (
      props.conditions.find((option) => option.label === "gut") ??
      props.conditions[0]
    )?.value ?? null,
  notes: "",
  count: 1,
  is_container: false,
  public_contents: true,
  label_hint: "",
  place_code: "",
  home_code: "",
  home_same: true,
});

const {
  form,
  photos,
  photosLost,
  place,
  home,
  steps,
  stepLabels,
  step,
  index,
  reached,
  goTo,
  errors,
  fieldError,
  preview,
  loadPreview,
  result,
  resultIds,
  resultItems,
  creating,
  createError,
  create,
  round,
  markPrinted,
  next,
  finish,
} = useCapture(defaults, { printing: () => props.printing !== null });

const countNumber = computed(() =>
  form.kind === "single"
    ? Math.max(1, Math.min(props.maxCount, Number(form.count) || 1))
    : 1,
);

// errors without a field on the current step, e.g. a number that is taken
const shownFields: Record<string, string[]> = {
  data: [
    "name",
    "category_id",
    "kind",
    "quantity",
    "unit",
    "condition_id",
    "description",
    "notes",
    "count",
  ],
  place: ["place_code", "place"],
  home: ["home_code", "home"],
  label: ["label_hint"],
};
const otherErrors = computed(() =>
  Object.entries(errors.value)
    .filter(
      ([field]) =>
        !(shownFields[step.value] ?? []).includes(field) &&
        !(step.value === "photos" && field.startsWith("photos")),
    )
    .map(([, message]) => message),
);
const photoError = computed(
  () =>
    Object.entries(errors.value).find(([field]) =>
      field.startsWith("photos"),
    )?.[1] ?? null,
);
watch(photos, (list) => {
  if (list.length) {
    photosLost.value = false;
  }
});

const valid = computed(() => {
  if (step.value === "data") {
    return (
      form.name.trim() !== "" &&
      form.condition_id !== null &&
      (form.kind === "bulk"
        ? form.quantity !== null && String(form.quantity) !== ""
        : Number(form.count) >= 1 && Number(form.count) <= props.maxCount)
    );
  }
  return true;
});

// the label and the summary need the label data of the server; it also checks the places
const loading = ref(false);
const open = async (position: number) => {
  const target = steps.value[position];
  if (!target || position > reached.value + 1) {
    return;
  }
  if (position > index.value && !valid.value) {
    return;
  }
  if (target === "label" || target === "summary") {
    loading.value = true;
    try {
      if (!(await loadPreview())) {
        return;
      }
    } catch (error) {
      errors.value = {
        preview: error instanceof Error ? error.message : String(error),
      };
      return;
    } finally {
      loading.value = false;
    }
  }
  goTo(position);
};

const labelOf = (options: Option[], value: unknown) =>
  options.find((option) => String(option.value) === String(value))?.label ??
  null;

// printer: the last one used in this browser, shared with the print page
const readPrinterId = () => {
  try {
    return Number(localStorage.getItem("labelPrinter")) || null;
  } catch {
    return null;
  }
};
const printers = computed(() => props.printing?.printers ?? []);
const printerId = ref(
  printers.value.find((entry) => entry.id === readPrinterId())?.id ??
    printers.value[0]?.id ??
    null,
);
const printer = computed(
  () => printers.value.find((entry) => entry.id === printerId.value) ?? null,
);
watch(printerId, (id) => {
  try {
    localStorage.setItem("labelPrinter", String(id));
  } catch {
    // private mode: not remembered
  }
});

// template per target (item or box), preselected is the default; changes apply to this round
const target = computed<LabelTargetName>(() =>
  form.is_container ? "box" : "item",
);
const templatesOf = (name: LabelTargetName) =>
  (props.printing?.templates ?? [])
    .filter((template) => template.target === name)
    .map((template) => ({ value: template.id, label: template.name }));
const templateIds = reactive<Partial<Record<LabelTargetName, number>>>({});
const designs = reactive<
  Record<
    string,
    { width_mm: number; height_mm: number; elements: LabelElement[] }
  >
>({});
const chooseTemplate = (name: LabelTargetName, id: number) => {
  const template = props.printing?.templates.find((entry) => entry.id === id);
  if (!template) {
    return;
  }
  templateIds[name] = id;
  designs[name] = {
    width_mm: template.width_mm,
    height_mm: template.height_mm,
    elements: JSON.parse(JSON.stringify(template.elements)),
  };
};
for (const name of ["item", "box"] as const) {
  const candidates = (props.printing?.templates ?? []).filter(
    (template) => template.target === name,
  );
  const preferred =
    candidates.find((template) => template.is_default) ?? candidates[0];
  if (preferred) {
    chooseTemplate(name, preferred.id);
  }
}
const design = computed(() => designs[target.value] ?? null);
// the label editor is opened on request ("Etikett anpassen")
const editing = ref(false);

// the preview shows the chosen photo before it is uploaded, and the hint as typed
const photoUrl = ref<string | null>(null);
watch(
  () => photos.value[0],
  (photo) => {
    if (photoUrl.value) {
      URL.revokeObjectURL(photoUrl.value);
    }
    photoUrl.value = photo ? URL.createObjectURL(photo) : null;
  },
  { immediate: true },
);
onBeforeUnmount(() => {
  if (photoUrl.value) {
    URL.revokeObjectURL(photoUrl.value);
  }
});
const previewData = computed<LabelData | null>(() =>
  preview.value
    ? {
        ...preview.value,
        photo_url: photoUrl.value,
        values: { ...preview.value.values, hint: form.label_hint },
      }
    : null,
);

const copies = ref<number | string>(1);
const copyCount = () => Math.max(1, Math.min(100, Number(copies.value) || 1));

const baseUrlConfirmed = computed(
  () => props.printing?.baseUrlConfirmed ?? false,
);
const confirmAddress = () =>
  router.post(
    url("/labels/confirm-address"),
    {},
    { preserveScroll: true, preserveState: true },
  );

// labels of the created items that are not printed yet, in the order of their numbers
const unprintedLabels = computed(() =>
  (result.value?.labels ?? []).filter(
    (label) => !round.value.find((entry) => entry.id === label.id)?.printed,
  ),
);
const anyPrinted = computed(() =>
  resultItems.value.some((entry) => entry.printed),
);
const unprintedResult = computed(() =>
  resultItems.value.filter((entry) => !entry.printed).map((entry) => entry.id),
);
const unprintedInRound = computed(() =>
  round.value.filter((entry) => !entry.printed).map((entry) => entry.id),
);

const printLabels = (): (PrintLabel & { name: string })[] =>
  unprintedLabels.value
    .filter((label) => designs[label.target])
    .map((label) => ({
      template: designs[label.target],
      data: label,
      name: label.code,
    }));

// a direct print notes the labels that came out; paper and images wait for the tick
let printingIds = [] as number[];
const afterPrint = (done: number | null) => {
  if (done !== null) {
    markPrinted(printingIds.slice(0, done));
  }
};

const {
  busy,
  printError,
  printed,
  printOnPaper,
  downloadPng,
  connections,
  connected,
  deviceName,
  connectPrinter,
  progress,
  printNiimbot,
  cancel,
  printedCount,
} = useLabelPrinting(
  printer,
  () => props.printing?.logoUrl ?? null,
  printLabels,
  copyCount,
  afterPrint,
);

const printDirect = async () => {
  printingIds = printLabels().map((label) => label.data.id);
  await printNiimbot();
  // stopped with an error: the labels that came out still count
  if (printError.value) {
    markPrinted(printingIds.slice(0, printedCount() ?? 0));
  }
};
const connectAndPrint = async (connection: (typeof connections)[number]) => {
  await connectPrinter(connection);
  if (connected.value) {
    await printDirect();
  }
};

// what the last button does
const directReady = computed(
  () => printer.value?.driver === "niimbot" && connected.value,
);
const willPrint = computed(
  () =>
    props.printing !== null &&
    design.value !== null &&
    baseUrlConfirmed.value &&
    (printer.value?.driver === "paper" || directReady.value),
);
const printSummary = computed(() => {
  if (!design.value) {
    return "Keine Vorlage: Es wird nur angelegt.";
  }
  if (!printer.value) {
    return "Kein Drucker: Es wird nur angelegt.";
  }
  if (!baseUrlConfirmed.value) {
    return "Die Adresse in den Codes ist noch nicht bestätigt (Schritt Etikett).";
  }
  if (printer.value.driver === "paper") {
    return `Druckt auf ${printer.value.name}.`;
  }
  if (directReady.value) {
    return `Druckt direkt auf ${deviceName.value ?? printer.value.name}.`;
  }
  return connections.length
    ? "Drucker nicht verbunden: erst anlegen, danach verbinden und drucken."
    : "Danach gibt es das Bild für die Niimbot-App.";
});

const createAndPrint = async () => {
  if (!(await create())) {
    return;
  }
  stuck.value = false;
  if (!willPrint.value) {
    return;
  }
  if (directReady.value) {
    await printDirect();
  } else {
    await printOnPaper();
  }
};

// "Etikett wurde aufgeklebt" after paper or an image
const stuck = ref(false);
watch(stuck, (value) => {
  if (value) {
    markPrinted(unprintedResult.value);
  }
});

const nextItem = () => {
  stuck.value = false;
  printed.value = null;
  printError.value = null;
  // the photo went with the item; the place stays for the next one
  next();
};

const showRound = ref(false);
const newRound = () => {
  finish();
  router.visit(url("/inventory/capture"), { preserveState: false });
};
</script>
