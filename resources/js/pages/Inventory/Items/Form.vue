<template>
  <UiPageHeader
    :title="item ? `${item.number} bearbeiten` : 'Neuer Gegenstand'"
  >
    <UiButton
      :href="item ? `/inventory/items/${item.id}` : '/inventory/items'"
      variant="secondary"
    >
      Zurück
    </UiButton>
  </UiPageHeader>

  <form class="space-y-6" @submit.prevent="submit">
    <UiAlert v-if="reservedNumber" type="info">
      Der Gegenstand bekommt die Nummer
      <strong class="font-mono">{{ reservedNumber }}</strong> vom Etikett auf
      Vorrat.
    </UiAlert>
    <UiAlert v-if="form.errors.reserved_number" type="error">
      {{ form.errors.reserved_number }}
    </UiAlert>
    <UiCard>
      <InventoryItemFields
        :form="form"
        :errors="form.errors"
        :categories="categories"
        :kinds="kinds"
        :units="units"
        :conditions="item ? null : conditions"
        :quantity-locked="item?.kind === 'bulk'"
        :quantity-hint="
          item?.kind === 'bulk'
            ? 'Die Menge folgt den Stellen: Wareneingang, Zählen, Verbraucht.'
            : null
        "
      >
        <template #more>
          <UiInput
            v-model="form.label_hint"
            label="Hinweis fürs Etikett"
            placeholder="z. B. Achtung, zerbrechlich"
            hint="Kurzer Text, den Vorlagen mit {hint} drucken. Leer: Das Element bleibt weg."
            :error="form.errors.label_hint"
          />
          <UiCheckbox
            v-model="form.no_label"
            label="Kein Etikett nötig"
            description="Gilt als bewusst ohne Etikett und fehlt unter „Etikett fehlt“, z. B. bei Kabeln oder Kleinkram."
          />
          <UiInput
            v-model="form.ean"
            label="EAN"
            inputmode="numeric"
            placeholder="z. B. 4006381333931"
            hint="Barcode der Verpackung. Damit findet das Erfassen den Gegenstand beim nächsten Scan."
            :error="form.errors.ean"
          />
        </template>
      </InventoryItemFields>
    </UiCard>

    <UiCard title="Box">
      <div class="space-y-4">
        <UiCheckbox
          v-model="form.is_container"
          label="Ist eine Box"
          description="Andere Gegenstände können darin liegen und ziehen mit ihr um."
        />
        <p v-if="form.errors.is_container" class="text-sm text-red-600">
          {{ form.errors.is_container }}
        </p>
        <UiCheckbox
          v-if="form.is_container"
          v-model="form.public_contents"
          label="Inhalt öffentlich zeigen"
          description="Später zeigt der QR-Code der Box ihre Inhaltsliste ohne Anmeldung."
        />
      </div>
    </UiCard>

    <UiCard
      v-if="!item"
      title="Fotos"
      :description="`Bis zu ${maxPhotos} Fotos, am Handy direkt mit der Kamera. Das erste wird das Vorschaubild.`"
    >
      <UiPhotoPicker
        v-model="form.photos"
        :max="maxPhotos"
        :error="photoError"
      />
    </UiCard>

    <UiCard title="Ort">
      <div class="grid gap-5 sm:grid-cols-2">
        <InventoryPlaceInput
          v-if="!item"
          v-model="form.place_code"
          label="Liegt in"
          :initial="place"
          :error="form.errors.place_code ?? form.errors.place"
        />
        <InventoryPlaceInput
          v-model="form.home_code"
          label="Stammplatz"
          :initial="item?.home ?? null"
          hint="Optional, nur für Dinge, die immer an denselben Platz zurück sollen."
          :error="form.errors.home_code ?? form.errors.home"
        />
      </div>
    </UiCard>

    <div class="flex justify-end">
      <UiButton type="submit" :disabled="form.processing">Speichern</UiButton>
    </div>
  </form>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useForm } from "@inertiajs/vue3";

/**
 * Form to create or change an item. The place of an existing item changes by moving it, the
 * condition by "Zustand ändern".
 */
const props = defineProps<{
  item: {
    id: number;
    number: string;
    name: string;
    description: string | null;
    category_id: number | null;
    kind: string;
    quantity: number;
    min_quantity: number | null;
    unit: string | null;
    is_container: boolean;
    public_contents: boolean;
    notes: string | null;
    label_hint: string | null;
    no_label: boolean;
    ean: string | null;
    home: Place | null;
  } | null;
  // number of a scanned stock label the new item gets
  reservedNumber?: string | null;
  // place of a new item, e.g. when created from the page of a location
  place: Place | null;
  categories: Option[];
  kinds: Option[];
  conditions: Option[];
  units: Option[];
  maxPhotos: number;
}>();

const form = useForm({
  name: props.item?.name ?? "",
  description: props.item?.description ?? "",
  category_id: props.item?.category_id ?? null,
  kind: props.item?.kind ?? "single",
  quantity: props.item?.quantity ?? 1,
  min_quantity: (props.item?.min_quantity ?? null) as number | string | null,
  // the units come from the settings; a new item starts with the first one ("Stück")
  unit: props.item?.unit ?? props.units[0]?.value ?? null,
  is_container: props.item?.is_container ?? false,
  public_contents: props.item?.public_contents ?? true,
  notes: props.item?.notes ?? "",
  label_hint: props.item?.label_hint ?? "",
  no_label: props.item?.no_label ?? false,
  ean: props.item?.ean ?? "",
  reserved_number: props.reservedNumber ?? null,
  // "gut" if it exists, otherwise the first condition
  condition_id:
    (
      props.conditions.find((option) => option.label === "gut") ??
      props.conditions[0]
    )?.value ?? null,
  place_code: props.place?.code ?? "",
  home_code: props.item?.home?.code ?? "",
  photos: [] as File[],
});

// photos of a new item (scaled down by UiPhotoPicker); existing items get photos on their page
const photoError = computed(
  () =>
    Object.entries(form.errors).find(([key]) =>
      key.startsWith("photos"),
    )?.[1] ?? null,
);

const submit = () =>
  props.item
    ? form.put(`/inventory/items/${props.item.id}`)
    : form.post("/inventory/items", { forceFormData: true });
</script>
