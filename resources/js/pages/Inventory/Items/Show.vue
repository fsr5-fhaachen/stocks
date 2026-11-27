<template>
  <UiPageHeader :title="item.name" :description="item.number">
    <UiButton
      v-if="can('inventory.move') && !item.retired_at && !isLocked"
      icon="right-left"
      @click="openMove()"
    >
      Umlagern
    </UiButton>
    <UiButton
      v-if="consumable && can('inventory.move') && !item.retired_at"
      variant="secondary"
      icon="minus"
      @click="openConsume()"
    >
      Verbraucht
    </UiButton>
    <UiButton
      v-if="can('inventory.move') && !item.retired_at"
      variant="secondary"
      icon="triangle-exclamation"
      @click="conditionOpen = true"
    >
      Zustand ändern
    </UiButton>
    <UiButton
      v-if="can('labels.print') && !item.retired_at"
      variant="secondary"
      icon="tag"
      :href="`/labels/print?items=${item.id}`"
    >
      Etikett drucken
    </UiButton>
    <UiButton
      v-if="can('inventory.edit')"
      variant="secondary"
      icon="pen"
      :href="`/inventory/items/${item.id}/edit`"
    >
      Bearbeiten
    </UiButton>
    <UiButton
      v-if="can('inventory.retire') && !item.retired_at && !isLocked"
      variant="ghost"
      icon="box-archive"
      @click="openRetire()"
    >
      Ausmustern
    </UiButton>
    <UiButton
      v-if="can('inventory.retire')"
      variant="ghost"
      icon="trash"
      @click="deleteOpen = true"
    >
      Löschen
    </UiButton>
  </UiPageHeader>

  <div class="space-y-4">
    <UiAlert v-if="errorMessages.length" type="error">
      {{ errorMessages.join(" ") }}
    </UiAlert>
    <UiAlert v-if="item.retired_at" type="warning">
      Ausgemustert am
      <UiDateTime :value="item.retired_at" :with-time="false" /> ({{
        item.retired_reason
      }}).
      <template v-if="item.retired_note">{{ item.retired_note }}</template>
    </UiAlert>
    <!-- notices of modules, e.g. "ausgegeben an … bis …" -->
    <template v-for="(notice, index) in notices" :key="index">
      <UiAlert
        v-if="notice.text"
        :type="notice.variant === 'danger' ? 'error' : notice.variant"
      >
        {{ notice.text }}
        <InertiaLink
          v-if="notice.href"
          :href="notice.href"
          class="ml-1 font-bold underline"
        >
          Öffnen
        </InertiaLink>
      </UiAlert>
    </template>
    <UiAlert v-if="item.away_from_home" type="warning">
      Liegt nicht an seinem Stammplatz
      <InventoryPlaceLink :place="item.home" />.
      <button
        v-if="can('inventory.move')"
        type="button"
        class="ml-1 font-bold underline"
        @click="returnHome"
      >
        Zurück an den Stammplatz
      </button>
    </UiAlert>
    <!-- right after creating or splitting: a new number needs a label, unless nobody wants one -->
    <UiAlert
      v-if="
        !item.label_printed_at &&
        !item.no_label &&
        !item.retired_at &&
        (can('labels.print') || can('inventory.edit'))
      "
      type="info"
    >
      Für {{ item.number }} wurde noch kein Etikett gedruckt.
      <InertiaLink
        v-if="can('labels.print')"
        :href="`/labels/print?items=${item.id}`"
        class="ml-1 font-bold underline"
      >
        Etikett drucken
      </InertiaLink>
      <button
        v-if="can('inventory.edit')"
        type="button"
        class="ml-1 font-bold underline"
        @click="setNoLabel(true)"
      >
        Kein Etikett nötig
      </button>
    </UiAlert>
    <UiAlert v-if="item.no_label && !item.retired_at" type="info">
      {{ item.number }} ist bewusst ohne Etikett.
      <button
        v-if="can('inventory.edit')"
        type="button"
        class="ml-1 font-bold underline"
        @click="setNoLabel(false)"
      >
        Braucht doch eins
      </button>
    </UiAlert>
    <!-- a split off part of old: its stock went into another item -->
    <UiAlert v-if="item.merged_into" type="info">
      Der Bestand liegt jetzt bei
      <InventoryPlaceLink :place="item.merged_into" />.
    </UiAlert>
  </div>

  <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="min-w-0 space-y-6 lg:col-span-2">
      <UiCard title="Daten">
        <dl class="grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-sm text-ink-muted">Artikelnummer</dt>
            <dd class="font-mono font-semibold">{{ item.number }}</dd>
          </div>
          <div v-if="item.ean">
            <dt class="text-sm text-ink-muted">EAN</dt>
            <dd class="font-mono">{{ item.ean }}</dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Art</dt>
            <dd>
              {{ item.kind_label }}
              <template v-if="item.kind === 'bulk'">
                · {{ item.quantity }} {{ item.unit }}
              </template>
              <template v-if="item.is_container"> · Box</template>
            </dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Kategorie</dt>
            <dd>{{ item.category ?? "—" }}</dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Zustand</dt>
            <dd>
              <UiBadge
                :variant="item.condition_is_defect ? 'danger' : 'neutral'"
              >
                {{ item.condition }}
              </UiBadge>
            </dd>
          </div>
          <div v-if="item.is_container">
            <dt class="text-sm text-ink-muted">Inhalt öffentlich</dt>
            <dd>{{ item.public_contents ? "ja" : "nein" }}</dd>
          </div>
          <div v-if="item.label_hint">
            <dt class="text-sm text-ink-muted">Hinweis fürs Etikett</dt>
            <dd>{{ item.label_hint }}</dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Etikett zuletzt gedruckt</dt>
            <dd>
              <UiDateTime
                v-if="item.label_printed_at"
                :value="item.label_printed_at"
                :with-time="false"
              />
              <template v-else>noch nie</template>
              <template v-if="item.no_label"> · kein Etikett nötig</template>
            </dd>
          </div>
          <div v-if="item.description" class="sm:col-span-2">
            <dt class="text-sm text-ink-muted">Beschreibung</dt>
            <dd class="whitespace-pre-line">{{ item.description }}</dd>
          </div>
          <div v-if="item.notes" class="sm:col-span-2">
            <dt class="text-sm text-ink-muted">Notiz</dt>
            <dd class="whitespace-pre-line">{{ item.notes }}</dd>
          </div>
        </dl>
      </UiCard>

      <UiCard v-if="item.is_container" title="Inhalt" flush>
        <template #actions>
          <UiButton
            v-if="can('labels.print') && item.contents.length"
            variant="secondary"
            size="sm"
            icon="tag"
            :href="`/labels/print?box=${item.id}`"
          >
            Etiketten für den Inhalt
          </UiButton>
          <UiButton
            v-if="can('inventory.move') && !item.retired_at"
            variant="secondary"
            size="sm"
            icon="box-open"
            @click="packOpen = 'box'"
          >
            Mengenartikel einpacken
          </UiButton>
          <UiButton
            v-if="can('inventory.move') && hasShort && !item.retired_at"
            variant="secondary"
            size="sm"
            icon="fill-drip"
            :disabled="filling"
            @click="fill"
          >
            Auffüllen
          </UiButton>
          <UiButton
            variant="secondary"
            size="sm"
            icon="file-pdf"
            @click="pdfOpen = true"
          >
            Inhaltsliste (PDF)
          </UiButton>
        </template>
        <div class="px-4 sm:px-6">
          <InventoryContentsTree
            v-if="item.contents.length"
            :items="item.contents"
          />
          <UiEmptyState v-else icon="box" title="Die Box ist leer" />
        </div>
      </UiCard>

      <!-- tabs of modules beside the history, e.g. "Verleih" -->
      <div
        v-if="tabs.length"
        class="flex gap-2 border-b border-line"
        role="tablist"
      >
        <button
          v-for="tab in [{ title: 'Verlauf', component: '' }, ...tabs]"
          :key="tab.title"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab.component"
          :class="[
            '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
            activeTab === tab.component
              ? 'border-accent text-accent'
              : 'border-transparent text-ink-muted hover:text-ink',
          ]"
          @click="activeTab = tab.component"
        >
          {{ tab.title }}
        </button>
      </div>
      <component
        :is="activeTabComponent"
        v-if="activeTabComponent"
        :item="item"
      />

      <UiCard v-else title="Verlauf" flush>
        <!-- fixed height, the history scrolls in itself instead of making the page longer and longer -->
        <ul
          v-if="history.length"
          class="max-h-[28rem] divide-y divide-line overflow-y-auto"
        >
          <li
            v-for="entry in history"
            :key="entry.key"
            class="space-y-1 px-4 py-3 sm:px-6"
          >
            <p class="flex flex-wrap items-baseline gap-x-2">
              <span class="font-medium">{{ entry.title }}</span>
              <button
                v-if="can('inventory.history.delete')"
                type="button"
                class="order-last ml-auto text-ink-muted hover:text-red-600"
                @click="deletingEntry = entry"
              >
                <FontAwesomeIcon :icon="['fas', 'trash']" />
                <span class="sr-only">Eintrag löschen</span>
              </button>
              <span v-if="entry.quantity !== null" class="text-sm">
                {{ entry.quantity }} {{ item.unit }}
              </span>
              <span class="text-sm text-ink-muted">
                <UiDateTime :value="entry.at" />
                <template v-if="entry.user"> · {{ entry.user }}</template>
              </span>
            </p>
            <p v-if="entry.from || entry.to" class="text-sm">
              <InventoryPlaceLink :place="entry.from" empty="nirgends" />
              →
              <InventoryPlaceLink :place="entry.to" empty="nirgends" />
            </p>
            <p
              v-if="entry.text"
              class="whitespace-pre-line text-sm text-ink-muted"
            >
              {{ entry.text }}
            </p>
            <div v-if="entry.photos.length" class="flex flex-wrap gap-2 pt-1">
              <a
                v-for="photo in entry.photos"
                :key="photo.id"
                :href="photo.url"
                target="_blank"
                data-file-viewer
              >
                <img
                  :src="photo.url"
                  alt=""
                  class="h-16 w-16 rounded object-cover"
                />
              </a>
            </div>
          </li>
        </ul>
        <UiEmptyState
          v-else
          icon="clock-rotate-left"
          title="Noch kein Verlauf"
        />
      </UiCard>
    </div>

    <!-- place first on phones, it is what people look for -->
    <div class="order-first space-y-6 lg:order-none">
      <InventoryStockCard
        v-if="item.kind === 'bulk'"
        :stocks="item.stocks"
        :quantity="item.quantity"
        :quantity-here="item.quantity_here ?? 0"
        :away-quantity="item.away_quantity"
        :unit="item.unit"
        :home="item.home"
        :consumable="consumable"
        :can-act="!item.retired_at && !isLocked"
        @move="openMove"
        @consume="openConsume"
        @retire="openRetire"
        @pack="packOpen = 'item'"
        @edit="editStock = $event"
      />
      <UiCard v-else title="Ort">
        <dl class="space-y-4">
          <div>
            <dt class="text-sm text-ink-muted">Liegt in</dt>
            <dd><InventoryPlaceLink :place="item.place" empty="kein Ort" /></dd>
          </div>
          <div v-if="item.location">
            <dt class="text-sm text-ink-muted">Standort der Box</dt>
            <dd><InventoryPlaceLink :place="item.location" /></dd>
          </div>
          <div>
            <dt class="text-sm text-ink-muted">Stammplatz</dt>
            <dd><InventoryPlaceLink :place="item.home" empty="keiner" /></dd>
          </div>
        </dl>
      </UiCard>

      <UiCard title="Fotos">
        <!-- the first photo is the preview in lists: shown large, with an accent frame and a label -->
        <div v-if="item.photos.length" class="grid grid-cols-2 gap-4">
          <figure
            v-for="(photo, index) in item.photos"
            :key="photo.id"
            :class="['space-y-2', index === 0 ? 'col-span-2' : '']"
          >
            <div class="relative">
              <a
                :href="photo.url"
                target="_blank"
                data-file-viewer
                class="block"
              >
                <img
                  :src="photo.url"
                  alt=""
                  :class="[
                    'aspect-square w-full rounded-md object-cover',
                    index === 0
                      ? 'ring-2 ring-accent ring-offset-2 ring-offset-surface'
                      : '',
                  ]"
                />
              </a>
              <!-- photo from a product database: credit as small as its licence allows -->
              <template v-if="photo.credit">
                <button
                  type="button"
                  class="absolute bottom-1.5 right-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-black/40 text-[10px] text-white/90 hover:bg-black/60"
                  :aria-label="creditText(photo.credit)"
                  :aria-expanded="shownCredit === photo.id"
                  @click="
                    shownCredit = shownCredit === photo.id ? null : photo.id
                  "
                >
                  <FontAwesomeIcon :icon="['fas', 'copyright']" />
                </button>
                <p
                  v-if="shownCredit === photo.id"
                  class="absolute inset-x-1.5 bottom-8 rounded bg-black/75 px-2 py-1 text-xs text-white"
                >
                  {{ creditText(photo.credit) }}
                  <a
                    v-if="photo.credit.url"
                    :href="photo.credit.url"
                    target="_blank"
                    rel="noopener"
                    class="underline"
                    >Quelle</a
                  >
                </p>
              </template>
              <span
                v-if="index === 0"
                class="pointer-events-none absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-accent px-2.5 py-1 text-xs font-semibold text-white shadow ring-2 ring-white"
              >
                <FontAwesomeIcon :icon="['fas', 'star']" />
                Vorschaubild
              </span>
              <InertiaLink
                v-if="can('inventory.edit')"
                :href="`/inventory/items/${item.id}/photos/${photo.id}`"
                method="delete"
                as="button"
                preserve-scroll
                class="absolute right-2 top-2 flex h-8 w-8 items-center justify-center rounded-full bg-surface/90 text-ink-muted shadow hover:text-red-600"
              >
                <FontAwesomeIcon :icon="['fas', 'trash']" />
                <span class="sr-only">Foto löschen</span>
              </InertiaLink>
            </div>
            <UiButton
              v-if="can('inventory.edit') && index > 0"
              variant="secondary"
              size="sm"
              icon="star"
              class="w-full whitespace-nowrap !px-2 !text-xs"
              :href="`/inventory/items/${item.id}/photos/${photo.id}/cover`"
              method="post"
            >
              Als Vorschau
            </UiButton>
            <figcaption v-else-if="index === 0" class="text-xs text-ink-muted">
              Erscheint in Listen und Suchergebnissen.
            </figcaption>
          </figure>
        </div>
        <p v-else class="text-sm text-ink-muted">Noch keine Fotos.</p>

        <form
          v-if="can('inventory.edit') && item.photos.length < maxPhotos"
          class="mt-4"
          @submit.prevent
        >
          <!-- Android opens the gallery directly: the camera gets its own button (usePlatform) -->
          <label
            v-if="isAndroid"
            :class="[
              'mb-3 inline-flex cursor-pointer items-center gap-2 rounded-md bg-accent px-3 py-2 text-sm font-medium text-white',
              photoForm.processing ? 'pointer-events-none opacity-50' : '',
            ]"
          >
            <FontAwesomeIcon :icon="['fas', 'camera']" />
            Foto mit der Kamera
            <input
              type="file"
              accept="image/*"
              capture="environment"
              class="sr-only"
              :disabled="photoForm.processing"
              @change="uploadPhotos"
            />
          </label>
          <label class="block">
            <span class="sr-only">Fotos hochladen</span>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              multiple
              class="block w-full text-sm text-ink-muted file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-accent file:px-3 file:py-2 file:text-sm file:font-medium file:text-white"
              :disabled="photoForm.processing"
              @change="uploadPhotos"
            />
          </label>
          <p v-if="photoError" class="mt-1 text-sm text-red-600">
            {{ photoError }}
          </p>
        </form>
      </UiCard>
    </div>
  </div>

  <!-- move -->
  <UiModal v-if="moveOpen" title="Umlagern" @close="moveOpen = false">
    <form class="space-y-5" @submit.prevent="move()">
      <InventoryMoveTargetSwitch v-model="moveForm.mode" />
      <UiSelect
        v-if="item.kind === 'bulk' && moveForm.mode !== 'home'"
        v-model="moveForm.from"
        label="Von"
        :options="sourceOptions"
        :placeholder="null"
        :error="moveForm.errors.from"
        @update:model-value="moveForm.quantity = stockAt(moveForm.from)"
      />
      <InventoryPlaceInput
        v-model="moveForm.target_code"
        label="Ziel"
        required
        :error="
          moveForm.errors.target_code ??
          moveForm.errors.target ??
          moveForm.errors.mode
        "
      />
      <UiInput
        v-if="item.kind === 'bulk' && moveForm.mode !== 'home'"
        v-model="moveForm.quantity"
        label="Menge"
        type="number"
        inputmode="numeric"
        :hint="`Dort liegen ${stockAt(moveForm.from)} ${item.unit ?? ''}.`"
        :error="moveForm.errors.quantity"
      />
      <UiInput
        v-model="moveForm.note"
        label="Notiz"
        :error="moveForm.errors.note"
      />
      <InventoryReservedConfirm
        :message="moveForm.errors.confirm"
        :processing="moveForm.processing"
        @confirm="move(true)"
      />
      <UiButton type="submit" class="w-full" :disabled="moveForm.processing">
        {{ moveForm.mode === "home" ? "Stammplatz setzen" : "Umlagern" }}
      </UiButton>
    </form>
  </UiModal>

  <!-- condition -->
  <InventoryConditionModal
    v-if="conditionOpen"
    :item="item"
    :conditions="conditions"
    @close="conditionOpen = false"
    @done="conditionOpen = false"
  />

  <!-- "Einpacken": pieces of this bulk item into a box or to a location; on a box: pieces of a
       scanned bulk item into this box -->
  <InventoryPackModal
    v-if="packOpen"
    :item="packOpen === 'item' ? item : null"
    :target="packOpen === 'box' ? item.number : null"
    :title="packOpen === 'box' ? 'Mengenartikel einpacken' : 'Einpacken'"
    @close="packOpen = null"
    @done="packOpen = null"
  />
  <!-- count a place and set its target ("Sollmenge") -->
  <InventoryStockEditModal
    v-if="editStock"
    :item-id="item.id"
    :unit="item.unit"
    :stock="editStock"
    @close="editStock = null"
    @done="editStock = null"
  />

  <!-- consume: quick action for consumables -->
  <InventoryConsumeModal
    v-if="consumeOpen"
    :item="item"
    :from="consumeFrom"
    :sources="item.stocks"
    @close="consumeOpen = false"
    @done="consumeOpen = false"
  />

  <!-- retire -->
  <UiModal v-if="retireOpen" title="Ausmustern" @close="retireOpen = false">
    <form class="space-y-5" @submit.prevent="retire()">
      <!-- bulk items: pieces from one place; or the whole item with the rest of all places -->
      <template v-if="item.kind === 'bulk'">
        <UiCheckbox
          v-model="retireForm.whole"
          label="Den ganzen Mengenartikel ausmustern"
          description="Die Nummer wird stillgelegt, was noch an Plätzen liegt, wird mit ausgebucht."
        />
        <template v-if="!retireForm.whole">
          <UiSelect
            v-model="retireForm.from"
            label="Von"
            :options="sourceOptions"
            :placeholder="null"
            :error="retireForm.errors.from"
          />
          <UiInput
            v-model="retireForm.quantity"
            label="Menge"
            type="number"
            inputmode="numeric"
            :hint="`Dort liegen ${stockAt(retireForm.from)} ${item.unit ?? ''}.`"
            :error="retireForm.errors.quantity"
          />
        </template>
      </template>
      <p class="text-sm text-ink-muted">
        {{
          isPartialRetire
            ? "Die Menge wird abgezogen und mit Grund im Verlauf vermerkt."
            : "Der Gegenstand behält Nummer, Fotos und Verlauf, hat aber keinen Ort mehr und verschwindet aus den Listen."
        }}
      </p>
      <UiSelect
        v-model="retireForm.reason"
        label="Grund"
        :options="retireReasons"
        :placeholder="null"
      />
      <UiTextarea
        v-model="retireForm.note"
        label="Notiz"
        :error="retireForm.errors.note"
      />
      <InventoryReservedConfirm
        :message="retireForm.errors.confirm"
        :processing="retireForm.processing"
        @confirm="retire(true)"
      />
      <UiButton
        type="submit"
        variant="danger"
        class="w-full"
        :disabled="retireForm.processing"
      >
        {{ isPartialRetire ? "Menge ausbuchen" : "Ausmustern" }}
      </UiButton>
    </form>
  </UiModal>

  <!-- contents of a box as pdf, optionally as letter for a window envelope -->
  <UiModal v-if="pdfOpen" title="Inhaltsliste (PDF)" @close="pdfOpen = false">
    <div class="space-y-5">
      <p class="text-sm text-ink-muted">
        A4 mit Druck-Logo, QR-Code und allen Gegenständen zum Abhaken.
      </p>
      <UiCheckbox
        v-model="pdfPhotos"
        label="Vorschaubilder mitdrucken"
        description="Das Vorschaubild der Box im Kopf der Liste und das jedes Gegenstands in seiner Zeile."
      />
      <UiCheckbox
        v-model="pdfLetter"
        label="Als Brief (DIN 5008)"
        description="Erste Seite mit Anschriftfeld und Faltmarken für einen Fensterumschlag DIN lang."
      />
      <UiTextarea
        v-if="pdfLetter"
        v-model="pdfRecipient"
        label="Anschrift des Empfängers"
        :rows="4"
        placeholder="Name&#10;Straße 1&#10;12345 Ort"
        hint="Leer lassen: eure eigene Anschrift."
      />
      <a
        :href="pdfUrl"
        class="flex w-full items-center justify-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-hover"
        @click="pdfOpen = false"
      >
        <FontAwesomeIcon :icon="['fas', 'download']" />
        Herunterladen
      </a>
    </div>
  </UiModal>

  <UiConfirmModal
    v-if="deletingEntry"
    title="Eintrag löschen"
    :message="`„${deletingEntry.title}“ wird aus dem Verlauf gelöscht, mit seinen Fotos. Ort und Zustand des Gegenstands bleiben, wie sie sind.`"
    @close="deletingEntry = null"
    @confirm="destroyEntry"
  />

  <UiConfirmModal
    v-if="deleteOpen"
    title="Gegenstand löschen"
    :message="`${item.number} wird endgültig gelöscht. Das geht nur bei Fehleingaben ohne Verlauf; sonst ausmustern.`"
    @close="deleteOpen = false"
    @confirm="destroy"
  />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { Link as InertiaLink, router, useForm, usePage } from "@inertiajs/vue3";
import type { MoveMode } from "../../../components/inventory/MoveTargetSwitch.vue";

type PhotoCredit = {
  source: string;
  author: string | null;
  license: string | null;
  url: string | null;
  host: string | null;
};
type Photo = { id: number; url: string; credit?: PhotoCredit | null };

/**
 * Detail page of an item: data, place, contents, photos and history.
 */
type Props = {
  item: {
    id: number;
    number: string;
    ean: string | null;
    name: string;
    description: string | null;
    kind: "single" | "bulk";
    kind_label: string;
    quantity: number;
    // pieces there, without those lent or missing; null for single items
    quantity_here: number | null;
    unit: string | null;
    is_container: boolean;
    public_contents: boolean;
    condition_id: number;
    condition: string;
    condition_is_defect: boolean;
    category: string | null;
    notes: string | null;
    place: Place | null;
    home: Place | null;
    location: Place | null;
    away_from_home: boolean;
    // bulk item: pieces per place, pieces away (lent, missing, not put away), minimum stock
    stocks: StockEntry[];
    away_quantity: number;
    min_quantity: number | null;
    // split off part of old whose stock went into another item
    merged_into: Place | null;
    retired_at: string | null;
    retired_reason: string | null;
    retired_note: string | null;
    label_hint: string | null;
    no_label: boolean;
    label_printed_at: string | null;
    photos: Photo[];
    contents: ContentsItem[];
  };
  history: {
    key: string;
    at: string;
    title: string;
    from: Place | null;
    to: Place | null;
    quantity: number | null;
    text: string | null;
    user: string | null;
    photos: Photo[];
    delete_url: string;
  }[];
  // notices of modules (App\Support\Extensions\ItemExtensions)
  notices: ItemNotice[];
  // tabs of modules, components "<Module>::<Component>" with the prop "item"
  tabs: { title: string; component: string }[];
  conditions: Option[];
  retireReasons: Option[];
  // bulk item whose unit is marked as consumable
  consumable: boolean;
  maxPhotos: number;
};

const props = defineProps<Props>();

const page = usePage<SharedProps>();
const { can } = usePermissions();

// credit of photos from product databases ("©" on the photo)
const shownCredit = ref<number | null>(null);
const creditText = (credit: PhotoCredit) =>
  credit.license
    ? `Foto: ${credit.author ?? credit.source}, ${credit.license}`
    : `Bild über ${credit.source}${credit.host ? `, Quelle: ${credit.host}` : ""}`;
const { compressImages } = useImageCompression();

// a module locks the item, e.g. while it is lent out
const isLocked = computed(() => props.notices.some((notice) => notice.lock));

// tabs of modules; "" is the history
const activeTab = ref("");
const activeTabComponent = computed(() =>
  activeTab.value ? useModuleComponent(activeTab.value) : null,
);

// errors of actions without own form field, e.g. "In der Box liegen noch Gegenstände."
const errorMessages = computed(() =>
  ["item", "target"].map((key) => page.props.errors?.[key]).filter(Boolean),
);

const photoErrorOf = (errors: Record<string, string>) =>
  Object.entries(errors).find(([key]) => key.startsWith("photos"))?.[1] ?? null;

// move
const moveOpen = ref(false);
// bulk item: its places as sources ("Von")
const sourceOptions = computed<Option[]>(() =>
  props.item.stocks
    .filter((stock) => stock.quantity > 0)
    .map((stock) => ({
      value: stock.key,
      label: `${stock.place ? stock.place.code : "Eingang"} (${stock.quantity} ${props.item.unit ?? ""})`,
    })),
);
const stockAt = (key: string | null) =>
  props.item.stocks.find((stock) => stock.key === key)?.quantity ?? 0;
const firstSource = () =>
  props.item.stocks.find((stock) => stock.quantity > 0)?.key ?? null;

const moveForm = useForm({
  item_id: props.item.id,
  target_code: "",
  quantity: props.item.quantity as number | null,
  // bulk item: the place the pieces come from
  from: null as string | null,
  note: "",
  // "Ort / Stammplatz / beides", starts with "Ort"
  mode: "place" as MoveMode,
});
const openMove = (key: string | null = null) => {
  if (props.item.kind === "bulk") {
    moveForm.from = key ?? firstSource();
    moveForm.quantity = stockAt(moveForm.from);
  }
  moveOpen.value = true;
};
// confirmed: asked before and answered "Ja, trotzdem" (the item is booked)
const move = (confirmed = false) =>
  // the id is taken when sending, the page can show another item by now
  moveForm
    .transform((data) => ({
      ...data,
      item_id: props.item.id,
      quantity: data.mode === "home" ? null : data.quantity,
      from: props.item.kind === "bulk" ? data.from : null,
      confirmed,
    }))
    .post("/inventory/move", {
      preserveScroll: true,
      onSuccess: () => {
        moveOpen.value = false;
        moveForm.reset();
      },
    });

const { isAndroid } = usePlatform();

// "Kein Etikett nötig" (App\Enums\LabelStatus)
const setNoLabel = (noLabel: boolean) =>
  router.post(
    `/inventory/items/${props.item.id}/no-label`,
    { no_label: noLabel },
    { preserveScroll: true },
  );

const returnHome = () =>
  router.post(
    `/inventory/items/${props.item.id}/return-home`,
    {},
    { preserveScroll: true },
  );

// condition
const conditionOpen = ref(false);

// box contents as pdf
const pdfOpen = ref(false);
const pdfPhotos = ref(false);
const pdfLetter = ref(false);
const pdfRecipient = ref("");
const pdfUrl = computed(() => {
  const query = new URLSearchParams();
  if (pdfPhotos.value) {
    query.set("photos", "1");
  }
  if (pdfLetter.value) {
    query.set("letter", "1");
    if (pdfRecipient.value.trim()) {
      query.set("recipient", pdfRecipient.value.trim());
    }
  }
  const search = query.toString();
  return `/inventory/items/${props.item.id}/contents.pdf${search ? `?${search}` : ""}`;
});

// photos
const photoForm = useForm({ photos: [] as File[] });
const photoError = computed(() => photoErrorOf(photoForm.errors));
const uploadPhotos = async (event: Event) => {
  const input = event.target as HTMLInputElement;
  photoForm.photos = await compressImages(input.files);
  photoForm.post(`/inventory/items/${props.item.id}/photos`, {
    preserveScroll: true,
    forceFormData: true,
    onFinish: () => {
      input.value = "";
    },
  });
};

// retire and delete
const retireOpen = ref(false);

// consume a part of a consumable ("verbraucht"), from one place
const consumeOpen = ref(false);
const consumeFrom = ref<string | null>(null);

// "Einpacken" of this bulk item ("item") or into this box ("box"); counting a place
const packOpen = ref<"item" | "box" | null>(null);
const editStock = ref<StockEntry | null>(null);
// "Auffüllen": bulk pieces below their target in this box get the difference from "Eingang"
const hasShort = computed(() =>
  props.item.contents.some((entry) => entry.stock?.short),
);
const filling = ref(false);
const fill = () =>
  router.post(
    "/inventory/stocks/fill",
    { code: props.item.number },
    {
      preserveScroll: true,
      onStart: () => (filling.value = true),
      onFinish: () => (filling.value = false),
    },
  );
const openConsume = (key: string | null = null) => {
  consumeFrom.value = key ?? firstSource();
  consumeOpen.value = true;
};

// bulk items: pieces from one place, or the whole item ("whole")
const retireForm = useForm({
  reason: "defective",
  note: "",
  quantity: 1 as number | null,
  from: null as string | null,
  whole: false,
});
const openRetire = (key: string | null = null) => {
  if (props.item.kind === "bulk") {
    retireForm.from = key ?? firstSource();
    retireForm.quantity = stockAt(retireForm.from);
    retireForm.whole = false;
  }
  retireOpen.value = true;
};
const isPartialRetire = computed(
  () => props.item.kind === "bulk" && !retireForm.whole,
);
const retire = (confirmed = false) =>
  retireForm
    .transform(({ whole, ...data }) => ({
      ...data,
      quantity: props.item.kind === "bulk" && !whole ? data.quantity : null,
      from: props.item.kind === "bulk" && !whole ? data.from : null,
      confirmed,
    }))
    .post(`/inventory/items/${props.item.id}/retire`, {
      preserveScroll: true,
      onSuccess: () => {
        retireOpen.value = false;
        retireForm.note = "";
      },
    });

// delete a wrong entry of the history
const deletingEntry = ref<Props["history"][number] | null>(null);
const destroyEntry = () =>
  router.delete(deletingEntry.value!.delete_url, {
    preserveScroll: true,
    onFinish: () => (deletingEntry.value = null),
  });

const deleteOpen = ref(false);
const destroy = () =>
  router.delete(`/inventory/items/${props.item.id}`, {
    onFinish: () => (deleteOpen.value = false),
  });
</script>
