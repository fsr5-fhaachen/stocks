<template>
  <UiPageHeader title="Scannen" />

  <div class="grid gap-6 lg:grid-cols-2">
    <div class="flex flex-col gap-4">
      <!-- typing or a hand scanner; on the pc the scanner also works without clicking the field -->
      <form class="flex gap-2 lg:order-last" @submit.prevent="submitTyped">
        <input
          v-model="typed"
          type="text"
          autocomplete="off"
          autocapitalize="characters"
          placeholder="Code eintippen, z. B. FSR5-004218"
          aria-label="Code"
          class="block w-full rounded-md border-0 bg-surface py-2.5 font-mono text-ink shadow-sm ring-1 ring-inset ring-line focus:ring-2 focus:ring-inset focus:ring-accent"
        />
        <UiButton type="submit" variant="secondary">Suchen</UiButton>
      </form>
      <!-- on phones the camera fills the screen and shrinks to the upper part once a result is shown,
           so it stays visible above the card and keeps scanning -->
      <InventoryCameraScanner
        :class="[
          'w-full transition-[height] duration-300 lg:aspect-video lg:h-auto',
          hasResult
            ? 'h-[36dvh]'
            : 'h-[calc(100dvh-14rem-var(--bottom-bar,0px)-env(safe-area-inset-bottom))]',
        ]"
        :autostart="autostart"
        @scan="scanned"
        @started="rememberCamera(true)"
        @stopped="rememberCamera(false)"
      >
        <template #actions="{ item }">
          <button
            v-if="card || error"
            type="button"
            :class="[item, 'lg:hidden']"
            @click="closeSheet"
          >
            <FontAwesomeIcon :icon="['fas', 'expand']" class="h-4" /> Kamera
            groß
          </button>
        </template>
      </InventoryCameraScanner>
    </div>

    <div class="space-y-4">
      <!-- second step of moving, or putting everything at one place -->
      <UiAlert v-if="mode" type="info">
        <p>
          <strong>{{ mode.type === "move" ? moveTitle : hereTitle }}:</strong>
          {{ modeText }}
        </p>
        <!-- switch left, leaving the mode right: stays in place however long the text is -->
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
          <InventoryMoveTargetSwitch v-model="moveMode" />
          <button
            type="button"
            class="font-bold underline"
            @click="mode = null"
          >
            {{ mode.type === "move" ? "Abbrechen" : "Beenden" }}
          </button>
        </div>
      </UiAlert>
      <UiAlert v-if="error" type="error">{{ error }}</UiAlert>
      <InventoryReservedConfirm
        :message="reserved?.message"
        @confirm="reserved?.retry()"
      />

      <!-- the card of the last scan -->
      <UiCard v-if="card?.type === 'item'">
        <div class="flex gap-4">
          <img
            v-if="card.photo_url"
            :src="card.photo_url"
            alt=""
            class="h-24 w-24 shrink-0 rounded-md object-cover"
          />
          <div class="min-w-0 space-y-1">
            <p class="text-lg font-semibold">{{ card.name }}</p>
            <p class="font-mono text-sm text-ink-muted">{{ card.number }}</p>
            <p class="text-sm">
              <template v-if="card.kind === 'bulk'"
                >{{ card.quantity }} {{ card.unit }}
                <template
                  v-if="
                    card.quantity_here !== null &&
                    card.quantity_here < card.quantity
                  "
                  >({{ card.quantity_here }} vorhanden)</template
                >
                ·
              </template>
              <template v-if="card.is_container"
                >Box mit {{ card.contents_count }} Gegenständen ·
              </template>
              <!-- bulk items lie at several places: their places with pieces -->
              <template v-if="card.kind === 'bulk'">
                <template v-for="(stock, index) in card.stocks" :key="stock.key"
                  ><template v-if="index">, </template
                  ><span class="font-mono">{{
                    stock.place?.code ?? "Eingang"
                  }}</span>
                  {{ stock.quantity }}</template
                >
                <span v-if="!card.stocks.length" class="text-ink-muted"
                  >an keinem Platz</span
                >
              </template>
              <InventoryPlaceLink v-else :place="card.place" empty="kein Ort" />
            </p>
            <UiBadge :variant="card.condition_is_defect ? 'danger' : 'neutral'">
              {{ card.condition }}
            </UiBadge>
            <UiBadge v-if="card.retired" class="ml-2">ausgemustert</UiBadge>
          </div>
        </div>
        <!-- notices of modules, e.g. "ausgegeben an … bis …" -->
        <template v-for="(notice, index) in card.notices" :key="index">
          <UiAlert
            v-if="notice.text"
            :type="notice.variant === 'danger' ? 'error' : notice.variant"
            class="mt-4"
          >
            {{ notice.text }}
          </UiAlert>
        </template>
        <!-- bulk items: from which place and how many -->
        <div
          v-if="card.kind === 'bulk' && canMove && card.stocks.length"
          class="mt-4 grid gap-3 sm:grid-cols-2"
        >
          <UiSelect
            v-if="card.stocks.length > 1"
            v-model="moveFrom"
            label="Von"
            :options="sourceOptions"
            :placeholder="null"
            @update:model-value="moveQuantity = stockAt(moveFrom)"
          />
          <UiInput
            v-model="moveQuantity"
            label="Menge zum Umlagern"
            type="number"
            inputmode="numeric"
            :hint="`Dort liegen ${stockAt(moveFrom)} ${card.unit ?? ''}.`"
          />
        </div>
        <div
          class="mt-4 grid grid-cols-2 gap-2 [&>*]:min-h-[44px] lg:[&>*]:text-base"
        >
          <!-- actions of modules, e.g. "Zur Buchung hinzufügen" -->
          <template v-for="notice in card.notices" :key="notice.text">
            <UiButton
              v-for="action in notice.actions"
              :key="action.href"
              :icon="action.icon"
              size="md"
              :href="action.href"
            >
              {{ action.title }}
            </UiButton>
          </template>
          <UiButton
            v-if="canMove && !isLocked"
            icon="right-left"
            size="md"
            @click="startMove"
            >Umlagern</UiButton
          >
          <UiButton
            v-if="canMove && card.consumable"
            variant="secondary"
            icon="minus"
            size="md"
            @click="consumeOpen = true"
          >
            Verbraucht
          </UiButton>
          <UiButton
            v-if="canMove"
            variant="secondary"
            icon="triangle-exclamation"
            size="md"
            @click="conditionOpen = true"
          >
            Zustand
          </UiButton>
          <UiButton
            v-if="
              can('inventory.retire') &&
              !card.retired &&
              !isLocked &&
              (card.kind !== 'bulk' || stockAt(moveFrom) > 0)
            "
            variant="secondary"
            icon="box-archive"
            size="md"
            @click="retireOpen = true"
          >
            Ausmustern
          </UiButton>
          <UiButton
            v-if="can('labels.print') && !card.retired"
            variant="secondary"
            icon="tag"
            size="md"
            :href="url(`/labels/print?items=${card.id}`)"
          >
            Etikett
          </UiButton>
          <!-- no href on surfaces without the detail page, e.g. the kiosk -->
          <UiButton
            v-if="card.href"
            variant="secondary"
            icon="arrow-up-right-from-square"
            size="md"
            :href="card.href"
          >
            {{ card.is_container ? "Inhalt" : "Öffnen" }}
          </UiButton>
        </div>
      </UiCard>

      <UiCard v-else-if="card?.type === 'location'">
        <p class="font-mono text-lg font-semibold">{{ card.full_code }}</p>
        <p class="text-sm text-ink-muted">
          {{ [card.type_label, card.name].filter(Boolean).join(" · ") }} ·
          {{ card.items_count }} Gegenstände
        </p>
        <div
          class="mt-4 grid grid-cols-2 gap-2 [&>*]:min-h-[44px] lg:[&>*]:text-base"
        >
          <UiButton
            v-if="
              can('inventory.move') &&
              card.location_type !== 'address' &&
              card.location_type !== 'building'
            "
            icon="right-left"
            size="md"
            @click="startHere(card.full_code)"
          >
            Hierher umlagern
          </UiButton>
          <UiButton
            v-if="can('labels.print')"
            variant="secondary"
            icon="tag"
            size="md"
            :href="url(`/labels/print?locations=${card.id}`)"
          >
            Etikett
          </UiButton>
          <UiButton
            v-if="card.href"
            variant="secondary"
            icon="arrow-up-right-from-square"
            size="md"
            :href="card.href"
          >
            Öffnen
          </UiButton>
        </div>
      </UiCard>

      <UiCard v-else-if="card?.type === 'reserved'">
        <p class="font-mono text-lg font-semibold">{{ card.number }}</p>
        <p class="text-sm text-ink-muted">{{ card.message }}</p>
        <UiButton
          v-if="can('inventory.edit') && card.href"
          class="mt-4"
          icon="plus"
          size="md"
          :href="card.href"
        >
          Gegenstand anlegen
        </UiButton>
      </UiCard>

      <UiEmptyState
        v-else-if="!error"
        icon="qrcode"
        title="Noch nichts gescannt"
        description="Halte die Kamera auf den Code eines Etiketts oder scanne mit dem Handscanner, dann entscheide, was damit passiert."
      />
    </div>
  </div>

  <InventoryConsumeModal
    v-if="consumeOpen && card?.type === 'item'"
    :item="card"
    :from="moveFrom"
    :sources="card.stocks"
    @close="consumeOpen = false"
    @done="afterAction"
  />
  <!-- bulk items: pieces from the place chosen above -->
  <InventoryRetireModal
    v-if="retireOpen && card?.type === 'item'"
    :item="card"
    :reasons="retireReasons"
    :from="card.kind === 'bulk' ? moveFrom : null"
    :there="card.kind === 'bulk' ? stockAt(moveFrom) : null"
    @close="retireOpen = false"
    @done="afterAction"
  />
  <InventoryConditionModal
    v-if="conditionOpen && card?.type === 'item'"
    :item="card"
    :conditions="conditions"
    @close="conditionOpen = false"
    @done="afterAction"
  />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import type { MoveMode } from "../../components/inventory/MoveTargetSwitch.vue";

type ScannedItem = {
  type: "item";
  id: number;
  number: string;
  name: string;
  kind: "single" | "bulk";
  quantity: number;
  // pieces there, without those lent or missing; null for single items
  quantity_here: number | null;
  unit: string | null;
  is_container: boolean;
  retired: boolean;
  place: Place | null;
  condition_id: number;
  condition: string;
  condition_is_defect: boolean;
  photo_url: string | null;
  contents_count: number;
  consumable: boolean;
  // bulk item: pieces per place
  stocks: StockEntry[];
  href: string | null;
  notices: ItemNotice[];
};
type ScannedLocation = {
  type: "location";
  location_type: LocationType;
  id: number;
  type_label: string;
  name: string | null;
  full_code: string;
  items_count: number;
  href: string | null;
};
type ScannedReserved = {
  type: "reserved";
  number: string;
  message: string;
  href: string | null;
};
type Scanned = ScannedItem | ScannedLocation | ScannedReserved;

/**
 * Scan page: the camera keeps running, every hit shows a card with the actions for the item, box or
 * location. "Umlagern" makes the next scan the target; "Hierher umlagern" at a location books every
 * following item there until it is ended.
 */
defineProps<{ conditions: Option[]; retireReasons: Option[] }>();

const { can } = usePermissions();
const { url } = useSurface();

const card = ref<Scanned | null>(null);
const error = ref<string | null>(null);
const mode = ref<
  { type: "move"; item: ScannedItem } | { type: "here"; code: string } | null
>(null);
const moveQuantity = ref<number | string>(1);
// bulk item: the place the pieces come from (item_stocks.place_key)
const moveFrom = ref<string | null>(null);
const stockAt = (key: string | null) =>
  card.value?.type === "item"
    ? (card.value.stocks?.find((stock) => stock.key === key)?.quantity ?? 0)
    : 0;
const sourceOptions = computed<Option[]>(() =>
  card.value?.type === "item"
    ? card.value.stocks
        .filter((stock) => stock.quantity > 0)
        .map((stock) => ({
          value: stock.key,
          label: `${stock.place ? stock.place.code : "Eingang"} (${stock.quantity})`,
        }))
    : [],
);
// the target becomes the place, the home or both; every move starts with "Ort"
const moveMode = ref<MoveMode>("place");
const moveTitle = computed(
  () =>
    ({
      place: "Umlagern",
      home: "Stammplatz ändern",
      both: "Umlagern mit Stammplatz",
    })[moveMode.value],
);
// text of the move and "Hierher umlagern" notices, built here so no stray space lands before the
// full stop
const modeText = computed(() => {
  const current = mode.value;
  if (current?.type === "move") {
    const { item } = current;
    const amount =
      item.kind === "bulk" && moveMode.value !== "home"
        ? ` (${moveQuantity.value} ${item.unit})`
        : "";
    return `${item.number} ${item.name}${amount}. Scanne jetzt das Ziel.`;
  }
  if (current?.type === "here") {
    return {
      place: `Jeder gescannte Gegenstand kommt nach ${current.code}.`,
      home: `Jeder gescannte Gegenstand bekommt ${current.code} als Stammplatz.`,
      both: `Jeder gescannte Gegenstand kommt nach ${current.code}, das wird auch sein Stammplatz.`,
    }[moveMode.value];
  }
  return "";
});
const hereTitle = computed(
  () =>
    ({
      place: "Hierher umlagern",
      home: "Stammplatz hier",
      both: "Hierher mit Stammplatz",
    })[moveMode.value],
);

const canMove = computed(
  () =>
    card.value?.type === "item" && !card.value.retired && can("inventory.move"),
);

// a module locks the item, e.g. while it is lent out
const isLocked = computed(
  () =>
    card.value?.type === "item" &&
    card.value.notices.some((notice) => notice.lock),
);

// the camera starts by itself when it was on last time
const readCamera = () => {
  try {
    return localStorage.getItem("scanCamera") === "1";
  } catch {
    return false;
  }
};
const autostart = readCamera();
const rememberCamera = (on: boolean) => {
  try {
    localStorage.setItem("scanCamera", on ? "1" : "0");
  } catch {
    // private mode: not remembered
  }
};

const lookup = async (code: string): Promise<Scanned | null> => {
  const response = await fetch(
    url(`/inventory/lookup?code=${encodeURIComponent(code)}`),
    {
      headers: { Accept: "application/json" },
    },
  );
  const data = await response.json();
  if (!response.ok) {
    error.value = data.message;
    return null;
  }
  return data;
};

const show = async (code: string) => {
  const found = await lookup(code);
  if (found) {
    card.value = found;
    if (found.type === "item") {
      // bulk items: the first place with pieces ("Eingang" first) and all that lies there
      moveFrom.value =
        found.stocks?.find((stock) => stock.quantity > 0)?.key ?? null;
      moveQuantity.value =
        found.kind === "bulk" ? stockAt(moveFrom.value) : found.quantity;
    }
  }
};

// a move: by the next scan (mode "move") or to the place of mode "here"
// a booked item asks first ("Ja, trotzdem" sends it again confirmed)
const reserved = ref<{ message: string; retry: () => void } | null>(null);
const move = (
  itemId: number,
  targetCode: string,
  quantity: number | null,
  onSuccess?: () => void,
  confirmed = false,
  target: MoveMode = "place",
  from: string | null = null,
) =>
  router.post(
    url("/inventory/move"),
    {
      item_id: itemId,
      target_code: targetCode,
      quantity: target === "home" ? null : quantity,
      from,
      confirmed,
      mode: target,
    },
    {
      preserveScroll: true,
      preserveState: true,
      onStart: () => {
        reserved.value = null;
      },
      onSuccess,
      onError: (errors) => {
        if (errors.confirm) {
          reserved.value = {
            message: errors.confirm,
            retry: () =>
              move(itemId, targetCode, quantity, onSuccess, true, target, from),
          };
          return;
        }
        error.value =
          errors.target_code ??
          errors.target ??
          errors.item ??
          errors.quantity ??
          errors.from ??
          errors.mode ??
          null;
      },
    },
  );

const scanned = async (code: string) => {
  error.value = null;

  if (mode.value?.type === "move") {
    const item = mode.value.item;
    mode.value = null;
    // afterwards the card shows the item with its new place
    move(
      item.id,
      code,
      item.kind === "bulk" ? Number(moveQuantity.value) : null,
      () => show(item.number),
      false,
      moveMode.value,
      item.kind === "bulk" ? moveFrom.value : null,
    );
    return;
  }

  if (mode.value?.type === "here") {
    const found = await lookup(code);
    if (found?.type === "item") {
      card.value = found;
      move(
        found.id,
        mode.value.code,
        null,
        () => show(found.number),
        false,
        moveMode.value,
      );
    } else if (found) {
      error.value =
        "Das ist kein Gegenstand. „Hierher umlagern“ beenden, um etwas anderes zu scannen.";
    }
    return;
  }

  await show(code);
};

useScannerInput(scanned);
const typed = ref("");
const submitTyped = () => {
  if (typed.value.trim()) {
    scanned(typed.value.trim());
    typed.value = "";
  }
};

const startMove = () => {
  if (card.value?.type === "item") {
    moveMode.value = "place";
    mode.value = { type: "move", item: card.value };
  }
};
const startHere = (code: string) => {
  moveMode.value = "place";
  mode.value = { type: "here", code };
};

const hasResult = computed(() => !!(card.value || mode.value || error.value));

// phones: hide the card again to see more of the camera
const closeSheet = () => {
  card.value = null;
  error.value = null;
};

// dialogs
const consumeOpen = ref(false);
const conditionOpen = ref(false);
const retireOpen = ref(false);
const afterAction = () => {
  consumeOpen.value = false;
  conditionOpen.value = false;
  retireOpen.value = false;
  if (card.value?.type === "item") {
    show(card.value.number);
  }
};
</script>
