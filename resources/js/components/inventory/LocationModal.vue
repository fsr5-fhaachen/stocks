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
        :label="several ? 'Erstes Kürzel' : 'Kürzel'"
        required
        :hint="codeHints[type]"
        :error="form.errors.code"
      />
      <!-- several shelves or compartments in a row ("Mehrere anlegen") -->
      <div v-if="canSeveral" class="space-y-3">
        <UiCheckbox
          v-model="several"
          label="Mehrere anlegen"
          description="Stocks zählt vom ersten Kürzel aus weiter: 1 → 2, R01 → R02, A → B."
        />
        <UiInput
          v-if="several"
          v-model="form.count"
          label="Anzahl"
          type="number"
          inputmode="numeric"
          :hint="`Höchstens ${maxCount}.`"
          :error="form.errors.count"
        />
      </div>
      <!-- the full code as it will be, while typing (App\Models\Location::fullCodeOf) -->
      <div
        v-if="planned.length"
        class="rounded-md bg-surface-muted p-3 text-sm"
        aria-live="polite"
      >
        <template v-if="planned.length === 1">
          <p>
            <span class="text-ink-muted">Kennziffer: </span>
            <template v-if="location && location.full_code !== planned[0].code">
              <span class="font-mono text-ink-muted line-through">{{
                location.full_code
              }}</span>
              →
            </template>
            <span
              :class="[
                'font-mono font-semibold',
                planned[0].taken ? 'text-red-600' : '',
              ]"
              >{{ planned[0].code }}</span
            >
          </p>
          <p v-if="planned[0].taken" class="mt-1 text-red-600">
            Die Kennziffer {{ planned[0].code }} gibt es schon.
          </p>
          <p v-else-if="childExample" class="mt-1 text-ink-muted">
            Darunter z. B. {{ childExample.from }} →
            <span class="font-mono">{{ childExample.to }}</span>
          </p>
        </template>
        <template v-else>
          <p>
            <span class="text-ink-muted">Legt {{ planned.length }} an: </span>
            <span class="font-mono font-semibold">{{ planned[0].code }}</span>
            bis
            <span class="font-mono font-semibold">{{
              planned[planned.length - 1].code
            }}</span>
          </p>
          <ul class="mt-2 flex flex-wrap gap-1.5">
            <li
              v-for="entry in planned"
              :key="entry.code"
              :class="[
                'rounded px-1.5 py-0.5 font-mono text-xs ring-1 ring-inset',
                entry.taken
                  ? 'bg-red-50 text-red-700 ring-red-600/30 dark:bg-red-950 dark:text-red-300'
                  : 'bg-surface ring-line',
              ]"
            >
              {{ entry.code }}
            </li>
          </ul>
          <p v-if="takenCount" class="mt-2 text-red-600">
            {{
              takenCount === 1
                ? "Eine Kennziffer gibt es schon (rot)."
                : `${takenCount} Kennziffern gibt es schon (rot).`
            }}
            Wähle ein anderes erstes Kürzel oder eine kleinere Anzahl.
          </p>
        </template>
      </div>
      <p v-else-if="sequenceError" class="text-sm text-red-600">
        {{ sequenceError }}
      </p>
      <UiInput v-model="form.name" label="Name" :error="form.errors.name" />
      <UiTextarea
        v-model="form.description"
        label="Beschreibung"
        :rows="2"
        :error="form.errors.description"
      />
      <UiCheckbox
        v-if="type !== 'address'"
        v-model="form.no_label"
        label="Kein Etikett nötig"
        description="Gilt als bewusst ohne Etikett und fehlt unter „Etikett fehlt“ und beim Druck aller Etiketten darunter."
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
        <UiButton
          type="submit"
          class="flex-1"
          :disabled="form.processing || takenCount > 0 || !!sequenceError"
        >
          {{
            codesChange
              ? "Trotzdem speichern"
              : planned.length > 1
                ? `${planned.length} anlegen`
                : "Speichern"
          }}
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
  // only buildings: their code is part of the codes below them
  show_in_code?: boolean;
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
          no_label?: boolean;
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
    // R01.2 before R01.10 (App\Models\Location::naturally)
    .sort((a, b) =>
      a.full_code.localeCompare(b.full_code, "de", { numeric: true }),
    )
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
  no_label: props.location?.no_label ?? false,
  // "Mehrere anlegen": number of locations, only sent when several is on
  count: 2 as number | string,
});

// --- preview of the full code and "Mehrere anlegen" ---

const maxCount = 50; // App\Support\Inventory\CodeSequence::MAX_COUNT
const canSeveral =
  !props.location && (type === "shelf" || type === "compartment");
const several = ref(false);

// codes in a row from the first one, as App\Support\Inventory\CodeSequence counts them
const sequence = (
  first: string,
  count: number,
): { codes: string[]; error: string | null } => {
  const digits = first.match(/^(.*?)(\d+)$/);
  if (digits) {
    const [, prefix, number] = digits;
    const codes = Array.from(
      { length: count },
      (_, offset) =>
        prefix + String(Number(number) + offset).padStart(number.length, "0"),
    );
    return { codes, error: null };
  }
  const letter = first.match(/^(.*?)([A-Z])$/);
  if (letter) {
    const [, prefix, start] = letter;
    const room = "Z".charCodeAt(0) - start.charCodeAt(0) + 1;
    if (count > room) {
      return {
        codes: [],
        error: `Von ${start} aus gehen nur ${room} Buchstaben bis Z.`,
      };
    }
    const codes = Array.from(
      { length: count },
      (_, offset) => prefix + String.fromCharCode(start.charCodeAt(0) + offset),
    );
    return { codes, error: null };
  }
  return {
    codes: [],
    error:
      "Das Kürzel muss auf eine Ziffer oder einen Buchstaben enden, damit Stocks weiterzählen kann.",
  };
};

const byId = computed(
  () => new Map(props.locations.map((entry) => [entry.id, entry])),
);
// full code of the parent as the children see it: a building that is not in the code passes
// on the code of its address
const parentCode = computed(() => {
  let current = form.parent_id ? byId.value.get(form.parent_id) : undefined;
  if (current?.type === "building" && !current.show_in_code) {
    current = current.parent_id ? byId.value.get(current.parent_id) : undefined;
  }
  return current?.full_code ?? null;
});
const separator = type === "compartment" ? "." : "-";
const fullCode = (code: string) =>
  parentCode.value ? `${parentCode.value}${separator}${code}` : code;

// codes that exist; the edited location keeps its own
const takenCodes = computed(
  () =>
    new Set(
      props.locations
        .filter((entry) => entry.id !== props.location?.id)
        .map((entry) => entry.full_code),
    ),
);
// the own codes: one, or the row of "Mehrere anlegen"
const codes = computed<{ codes: string[]; error: string | null }>(() => {
  const code = form.code.trim().toUpperCase();
  if (!code || !/^[A-Z0-9]+$/.test(code)) {
    return { codes: [], error: null };
  }
  const count = several.value
    ? Math.max(1, Math.min(maxCount, Number(form.count) || 1))
    : 1;
  return count > 1 ? sequence(code, count) : { codes: [code], error: null };
});
const sequenceError = computed(() => codes.value.error);
const planned = computed<{ code: string; taken: boolean }[]>(() =>
  codes.value.codes.map((code) => {
    const full = fullCode(code);
    return { code: full, taken: takenCodes.value.has(full) };
  }),
);
const takenCount = computed(
  () => planned.value.filter((entry) => entry.taken).length,
);

// a changed code or parent changes the codes below: one of them as example
const childExample = computed(() => {
  if (!props.location || planned.value.length !== 1) {
    return null;
  }
  const own = props.location.full_code;
  const next = planned.value[0].code;
  if (own === next) {
    return null;
  }
  const child = props.locations.find(
    (entry) =>
      entry.id !== props.location?.id &&
      (entry.full_code.startsWith(`${own}-`) ||
        entry.full_code.startsWith(`${own}.`)),
  );
  return child
    ? { from: child.full_code, to: next + child.full_code.slice(own.length) }
    : null;
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
    form
      .transform(({ count: _count, ...data }) => data)
      .put(`/inventory/locations/${props.location.id}`, options);
  } else {
    form
      .transform(({ count, ...data }) =>
        several.value ? { ...data, count: Number(count) || 1 } : data,
      )
      .post("/inventory/locations", options);
  }
};

const deleteError = ref<string | null>(null);
const destroy = () =>
  router.delete(`/inventory/locations/${props.location!.id}`, {
    onError: (errors) => (deleteError.value = errors.location ?? null),
  });
</script>
