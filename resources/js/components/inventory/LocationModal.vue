<template>
  <UiModal :title="title" @close="emit('close')">
    <form class="space-y-5" @submit.prevent="submit">
      <UiSelect
        v-if="parentTypes.length"
        v-model="form.parent_id"
        :label="`Liegt in (${parentTypes.map((parentType) => typeLabels[parentType]).join(' oder ')})`"
        :options="parentOptions"
        :placeholder="null"
      />
      <p v-if="form.errors.parent_id" class="text-sm text-red-600">
        {{ form.errors.parent_id }}
      </p>
      <UiInput
        v-model="form.code"
        label="Kürzel"
        required
        :hint="codeHints[type]"
        :error="form.errors.code"
      />
      <UiInput v-model="form.name" label="Name" :error="form.errors.name" />
      <UiTextarea
        v-model="form.description"
        label="Beschreibung"
        :rows="2"
        :error="form.errors.description"
      />
      <div v-if="type === 'building'" class="space-y-2">
        <UiCheckbox v-model="form.show_in_code" label="Im Code zeigen" />
        <p class="text-xs text-ink-muted">
          An: Die Räume darunter heißen z. B. EU-A-014. Aus: EU-014, das Gebäude
          steht nur im Pfad.
        </p>
        <p v-if="form.errors.show_in_code" class="text-sm text-red-600">
          {{ form.errors.show_in_code }}
        </p>
      </div>
      <UiAlert
        v-if="codesChange"
        type="warning"
        :message="`Die Kennziffern von ${descendants} ${descendants === 1 ? 'Standort' : 'Standorten'} darunter ändern sich. Deren Etiketten müssen neu gedruckt werden, die alten Kennziffern werden nicht mehr gefunden. QR-Codes auf den Etiketten gehen weiter.`"
      />
      <UiAlert v-if="deleteError" type="error" :message="deleteError" />
      <div class="flex flex-col gap-3 sm:flex-row">
        <UiButton type="submit" class="flex-1" :disabled="form.processing">
          {{ codesChange ? "Trotzdem speichern" : "Speichern" }}
        </UiButton>
        <UiButton
          v-if="location"
          variant="danger"
          icon="trash"
          @click="destroy"
        >
          Löschen
        </UiButton>
      </div>
    </form>
  </UiModal>
</template>

<script setup lang="ts">
import { computed, ref, type PropType } from "vue";
import { router, useForm } from "@inertiajs/vue3";

type LocationOption = {
  id: number;
  parent_id: number | null;
  type: LocationType;
  full_code: string;
  name: string | null;
};

/**
 * Create or change a location. The level follows from the parent (below an address the page says
 * whether a room or a building is created); the server checks it. Switching "Im Code zeigen" of a
 * building with locations below it asks first, because their codes change.
 */
const props = defineProps({
  // location to change, null for a new one
  location: {
    type: Object as PropType<
      | (LocationOption & {
          code: string;
          description: string | null;
          show_in_code: boolean;
        })
      | null
    >,
    default: null,
  },
  parentId: { type: Number as PropType<number | null>, default: null },
  // level of a new location where the parent allows two (address: room or building)
  type: { type: String as PropType<LocationType | null>, default: null },
  // all locations, for the choice of the parent
  locations: { type: Array as PropType<LocationOption[]>, required: true },
});

const emit = defineEmits<{ close: [] }>();

const typeLabels: Record<LocationType, string> = {
  address: "Adresse",
  building: "Gebäude",
  room: "Raum",
  shelf: "Lagerplatz",
  compartment: "Fach",
};
// levels a location of each level can lie in (App\Enums\LocationType::childTypes)
const parentLevels: Record<LocationType, LocationType[]> = {
  address: [],
  building: ["address"],
  room: ["address", "building"],
  shelf: ["room"],
  compartment: ["shelf"],
};
const firstChild: Record<LocationType, LocationType | null> = {
  address: "room",
  building: "room",
  room: "shelf",
  shelf: "compartment",
  compartment: null,
};
const codeHints: Record<LocationType, string> = {
  address: "Kurz für die Adresse, z. B. EU",
  building: "Kurz für das Gebäude, z. B. A",
  room: "Raumnummer wie an der Tür, z. B. 014",
  shelf: "Art und Nummer: R03 für Regal, S01 für Schrank, B01 für Bodenplatz",
  compartment: "Regalboden von unten gezählt, z. B. 4",
};

const parent = props.locations.find(
  (location) => location.id === props.parentId,
);
const type: LocationType =
  props.location?.type ??
  props.type ??
  (parent ? firstChild[parent.type]! : "address");
const parentTypes = parentLevels[type];

const parentOptions = computed(() =>
  props.locations
    .filter((location) => parentTypes.includes(location.type))
    .map((location) => ({
      value: location.id,
      label: [
        location.full_code,
        location.name,
        parentTypes.length > 1 ? typeLabels[location.type] : null,
      ]
        .filter(Boolean)
        .join(" · "),
    })),
);

const title = props.location
  ? `${props.location.full_code} bearbeiten`
  : `${typeLabels[type]} anlegen`;

const form = useForm({
  parent_id: props.parentId,
  type,
  code: props.location?.code ?? "",
  name: props.location?.name ?? "",
  description: props.location?.description ?? "",
  show_in_code: props.location?.show_in_code ?? false,
});

// locations below the edited one, their codes change with "Im Code zeigen"
const descendants = computed(() => {
  if (!props.location) {
    return 0;
  }
  const below = new Set([props.location.id]);
  let count = 0;
  // sorted by full code, a child can come before its parent: repeat until nothing is added
  for (let added = true; added;) {
    added = false;
    for (const location of props.locations) {
      if (
        location.parent_id &&
        below.has(location.parent_id) &&
        !below.has(location.id)
      ) {
        below.add(location.id);
        count++;
        added = true;
      }
    }
  }
  return count;
});
// shown once the switch changed, the button then saves "trotzdem"
const codesChange = computed(
  () =>
    !!props.location &&
    type === "building" &&
    form.show_in_code !== props.location.show_in_code &&
    descendants.value > 0,
);

const submit = () => {
  const options = { preserveScroll: true, onSuccess: () => emit("close") };
  if (props.location) {
    form.put(`/inventory/locations/${props.location.id}`, options);
  } else {
    form.post("/inventory/locations", options);
  }
};

const deleteError = ref<string | null>(null);
const destroy = () =>
  router.delete(`/inventory/locations/${props.location!.id}`, {
    onError: (errors) => (deleteError.value = errors.location ?? null),
  });
</script>
