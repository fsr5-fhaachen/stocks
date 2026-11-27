import { computed, reactive, ref, watch } from "vue";
import type { ItemFieldsForm } from "../components/inventory/ItemFields.vue";

/**
 * State of the guided capture (pages/Inventory/Capture.vue): the form, the current step, the
 * created items of this round and the requests to the server. Form, step and round live in the
 * sessionStorage of the tab, so reloading the page keeps them; photos are files and can not be
 * kept, they have to be chosen again.
 */
export type CaptureForm = ItemFieldsForm & {
  // equal single pieces ("Anzahl gleicher Stücke")
  count: number | string;
  is_container: boolean;
  public_contents: boolean;
  label_hint: string;
  // "Kein Etikett nötig": nothing is printed, the item does not count as "Etikett fehlt"
  no_label: boolean;
  place_code: string;
  home_code: string;
  // home = place ("Auch als Stammplatz")
  home_same: boolean;
  // barcode of the packaging
  ean: string;
};

export type CaptureStep =
  "data" | "photos" | "place" | "home" | "label" | "summary";

export type CapturedItem = {
  id: number;
  number: string;
  name: string;
  place: Place | null;
  href: string;
  printed: boolean;
  no_label: boolean;
};

type Stored = {
  form: CaptureForm;
  step: CaptureStep;
  reached: number;
  place: Place | null;
  home: Place | null;
  key: string;
  round: CapturedItem[];
  result: number[] | null;
  // photos chosen when the state was stored; files are not kept
  photos: number;
};

const STORAGE_KEY = "stocks.capture";

const STEP_LABELS: Record<CaptureStep, string> = {
  data: "Daten",
  photos: "Bilder",
  place: "Ort",
  home: "Stammplatz",
  label: "Etikett",
  summary: "Übersicht",
};

// fields of the server that belong to a step, for jumping to the first error
const FIELD_STEPS: [RegExp, CaptureStep][] = [
  [/^photos/, "photos"],
  [/^(place_code|place)$/, "place"],
  [/^(home_code|home)$/, "home"],
  [/^label_hint$/, "label"],
  [/^capture_key$/, "summary"],
];

const newKey = () =>
  typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

const xsrfToken = () =>
  decodeURIComponent(
    document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? "",
  );

const readStored = (): Partial<Stored> | null => {
  try {
    return JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? "null");
  } catch {
    return null;
  }
};

export default function (
  defaults: () => CaptureForm,
  options: { printing: () => boolean },
) {
  const { url } = useSurface();
  const stored = readStored();

  const form = reactive<CaptureForm>({ ...defaults(), ...stored?.form });
  const photos = ref<File[]>([]);
  // photos taken from a product database: their reference "<code>:<index>", the server keeps
  // the origin (credit, licence) with the photo
  const photoRefs = new WeakMap<File, string>();
  const place = ref<Place | null>(stored?.place ?? null);
  const home = ref<Place | null>(stored?.home ?? null);
  const key = ref(stored?.key ?? newKey());
  const round = ref<CapturedItem[]>(stored?.round ?? []);
  // ids of the items of the last "Erstellen", shown in the result view
  const resultIds = ref<number[] | null>(stored?.result ?? null);
  // the photos were chosen before a reload and are gone now
  const photosLost = ref(false);

  const steps = computed<CaptureStep[]>(() => [
    "data",
    "photos",
    "place",
    ...(form.home_same ? [] : (["home"] as const)),
    ...(options.printing() ? (["label"] as const) : []),
    "summary",
  ]);
  const stepLabels = computed(() =>
    steps.value.map((step) => STEP_LABELS[step]),
  );

  const step = ref<CaptureStep>(stored?.step ?? "data");
  // the furthest step reached so far; earlier ones can be opened again in the step line
  const reached = ref(stored?.reached ?? 0);
  const index = computed(() => Math.max(0, steps.value.indexOf(step.value)));
  // a step that disappeared (e.g. "Stammplatz" after ticking the box again) moves to its neighbour
  watch(steps, (list) => {
    if (!list.includes(step.value)) {
      step.value = list[Math.min(index.value, list.length - 1)];
    }
    reached.value = Math.min(reached.value, list.length - 1);
  });
  const goTo = (position: number) => {
    const target = steps.value[position];
    if (target) {
      step.value = target;
      reached.value = Math.max(reached.value, position);
      window.scrollTo({ top: 0 });
    }
  };

  if ((stored?.photos ?? 0) > 0 && !stored?.result) {
    photosLost.value = true;
  }

  watch(
    [form, step, reached, place, home, key, round, resultIds, photos],
    () => {
      try {
        sessionStorage.setItem(
          STORAGE_KEY,
          JSON.stringify({
            form,
            step: step.value,
            reached: reached.value,
            place: place.value,
            home: home.value,
            key: key.value,
            round: round.value,
            result: resultIds.value,
            photos: photos.value.length,
          } satisfies Stored),
        );
      } catch {
        // private mode or full storage: the round is only kept until the page is left
      }
    },
    { deep: true },
  );

  // errors of the server, one message per field
  const errors = ref<Record<string, string>>({});
  const errorStep = (fields: string[]): CaptureStep => {
    for (const field of fields) {
      const match = FIELD_STEPS.find(([pattern]) => pattern.test(field));
      if (!match) {
        return "data";
      }
      let target = match[1];
      if (target === "home" && form.home_same) {
        target = "place";
      }
      if (target === "label" && !steps.value.includes("label")) {
        target = "data";
      }
      return target;
    }
    return step.value;
  };
  // the place errors of the server have the field "place"/"home", the inputs "place_code"/"home_code"
  const fieldError = (...fields: string[]) =>
    fields.map((field) => errors.value[field]).find(Boolean) ?? null;

  const payload = (withPhotos: boolean) => {
    const body = new FormData();
    const single = form.kind === "single";
    const values: Record<string, string | number | boolean | null> = {
      name: form.name,
      description: form.description,
      category_id: form.category_id,
      kind: form.kind,
      quantity: single ? null : form.quantity,
      unit: single ? null : form.unit,
      condition_id: form.condition_id,
      notes: form.notes,
      label_hint: form.label_hint,
      no_label: form.no_label,
      is_container: form.is_container,
      public_contents: form.public_contents,
      place_code: form.place_code,
      home_code: form.home_same ? form.place_code : form.home_code,
      count: single ? Number(form.count) || 1 : 1,
      ean: form.ean.trim(),
      capture_key: key.value,
    };
    for (const [name, value] of Object.entries(values)) {
      if (value === null || value === "") {
        continue;
      }
      body.append(
        name,
        typeof value === "boolean" ? (value ? "1" : "0") : String(value),
      );
    }
    if (withPhotos) {
      photos.value.forEach((photo, index) => {
        body.append(`photos[${index}]`, photo);
        const reference = photoRefs.get(photo);
        if (reference) {
          body.append(`photo_refs[${index}]`, reference);
        }
      });
    }
    return body;
  };

  // a json request of the capture; a validation error fills "errors" and moves to its step
  const send = async <T>(path: string, body: FormData): Promise<T | null> => {
    const response = await fetch(url(path), {
      method: "POST",
      body,
      headers: { Accept: "application/json", "X-XSRF-TOKEN": xsrfToken() },
    });
    const data = await response.json().catch(() => ({}));
    if (response.status === 422) {
      errors.value = Object.fromEntries(
        Object.entries(
          (data.errors ?? {}) as Record<string, string | string[]>,
        ).map(([field, messages]) => [
          field,
          Array.isArray(messages) ? messages[0] : messages,
        ]),
      );
      step.value = errorStep(Object.keys(errors.value));
      return null;
    }
    if (!response.ok) {
      throw new Error(
        data.message ??
          `Der Server hat mit ${response.status} geantwortet. Versuch es noch einmal.`,
      );
    }
    errors.value = {};
    return data as T;
  };

  // label data of the item before it exists, the number is a placeholder
  const preview = ref<LabelData | null>(null);
  const loadPreview = async () => {
    const data = await send<{ label: LabelData }>(
      "/inventory/capture/preview",
      payload(false),
    );
    preview.value = data?.label ?? null;
    return preview.value !== null;
  };

  // create the item(s); a repeated request with the same key gets the same items back
  const result = ref<{ items: CapturedItem[]; labels: LabelData[] } | null>(
    null,
  );
  const creating = ref(false);
  const createError = ref<string | null>(null);
  const create = async () => {
    creating.value = true;
    createError.value = null;
    try {
      const data = await send<{
        items: Omit<CapturedItem, "printed">[];
        labels: LabelData[];
      }>("/inventory/capture", payload(true));
      if (!data) {
        return false;
      }
      const items = data.items.map((item) => ({
        ...item,
        no_label: !!item.no_label,
        printed: false,
      }));
      round.value = [
        ...items,
        ...round.value.filter(
          (entry) => !items.some((item) => item.id === entry.id),
        ),
      ];
      result.value = { items, labels: data.labels };
      resultIds.value = items.map((item) => item.id);
      return true;
    } catch (error) {
      createError.value =
        error instanceof Error ? error.message : String(error);
      return false;
    } finally {
      creating.value = false;
    }
  };

  // items of the result view; after a reload without the labels (printed from the print page)
  const resultItems = computed(
    () =>
      result.value?.items.map(
        (item) => round.value.find((entry) => entry.id === item.id) ?? item,
      ) ?? round.value.filter((entry) => resultIds.value?.includes(entry.id)),
  );

  // note the labels as printed (filter "noch ohne Etikett")
  const markPrinted = async (ids: number[]) => {
    if (!ids.length) {
      return;
    }
    await fetch(url("/labels/printed"), {
      method: "POST",
      // the server answers with a redirect back, which is not needed here
      redirect: "manual",
      body: JSON.stringify({ items: ids }),
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-XSRF-TOKEN": xsrfToken(),
      },
    });
    for (const entry of round.value) {
      if (ids.includes(entry.id)) {
        entry.printed = true;
      }
    }
  };

  // "Nächster Gegenstand": keep what is the same for the next one, empty the rest
  const next = () => {
    const keep = defaults();
    Object.assign(form, {
      name: keep.name,
      description: keep.description,
      notes: keep.notes,
      label_hint: keep.label_hint,
      no_label: keep.no_label,
      count: keep.count,
      ean: keep.ean,
    });
    photos.value = [];
    photosLost.value = false;
    preview.value = null;
    result.value = null;
    resultIds.value = null;
    errors.value = {};
    key.value = newKey();
    step.value = "data";
    reached.value = 0;
    window.scrollTo({ top: 0 });
  };

  // "Fertig": a new round starts from scratch
  const finish = () => {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch {
      // nothing kept
    }
  };

  return {
    form,
    photos,
    photoRefs,
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
  };
}
