<template>
  <UiPageHeader title="Einstellungen" />

  <UiCard
    title="Logos und Symbole"
    description="Die Logos stehen oben links, auf der Anmeldeseite, am Kiosk und auf der Verleihseite: im hellen Modus das Logo für helle Hintergründe, im dunklen das für dunkle. Gibt es nur eines, gilt es in beiden. Auf dem Home-Bildschirm steht das eigene Symbol dafür, ohne das Logo für helle Hintergründe auf Weiß, ohne beide ein „S“ auf der Akzentfarbe. Das Symbol (Favicon) zeigt der Browser im Tab. Das Druck-Logo kommt auf Etiketten, PDFs und Mails."
    class="mb-6"
  >
    <form
      class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3"
      @submit.prevent="saveBranding"
    >
      <div v-for="kind in brandingKinds" :key="kind.key" class="space-y-3">
        <p class="text-sm font-medium">{{ kind.label }}</p>
        <!-- preview on the background the logo is made for, so a white logo is visible -->
        <div
          :class="[
            'flex h-20 items-center justify-center rounded-md p-2 ring-1 ring-line',
            kind.background === 'dark'
              ? 'bg-gray-900 text-gray-400'
              : kind.background === 'light'
                ? 'bg-white text-gray-500'
                : 'bg-surface-muted text-ink-muted',
          ]"
        >
          <img
            v-if="previews[kind.key]"
            :src="previews[kind.key]!"
            alt=""
            class="max-h-full max-w-full object-contain"
          />
          <span v-else class="text-sm">Standard</span>
        </div>
        <input
          type="file"
          :accept="kind.accept"
          class="block w-full text-sm text-ink-muted file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-surface-muted file:px-3 file:py-2 file:text-sm file:font-medium file:text-ink"
          @change="choose(kind.key, $event)"
        />
        <p class="text-xs text-ink-muted">{{ kind.hint }}</p>
        <p v-if="brandingForm.errors[kind.key]" class="text-sm text-red-600">
          {{ brandingForm.errors[kind.key] }}
        </p>
        <UiButton
          v-if="previews[kind.key]"
          variant="ghost"
          size="sm"
          icon="trash"
          @click="removeImage(kind.key)"
        >
          Standard verwenden
        </UiButton>
      </div>
      <div class="flex justify-end sm:col-span-2 lg:col-span-3">
        <UiButton
          type="submit"
          :disabled="brandingForm.processing || !brandingForm.isDirty"
        >
          Bilder speichern
        </UiButton>
      </div>
    </form>
  </UiCard>

  <form class="space-y-6" @submit.prevent="submit">
    <UiCard title="Organisation">
      <div class="grid gap-5 sm:grid-cols-3">
        <UiInput
          v-model="form.organization.name"
          label="Name"
          required
          :error="form.errors['organization.name']"
        />
        <UiInput
          v-model="form.organization.short_name"
          label="Kurzname"
          required
          :error="form.errors['organization.short_name']"
        />
        <UiInput
          v-model="form.organization.accent_color"
          label="Akzentfarbe"
          type="color"
          hint="Wird nach dem Neuladen der Seite sichtbar."
          :error="form.errors['organization.accent_color']"
        />
        <UiTextarea
          v-model="form.organization.address"
          label="Anschrift"
          class="sm:col-span-3"
          :rows="4"
          placeholder="Fachschaftsrat …&#10;Straße 1&#10;52066 Aachen"
          hint="Für den Briefkopf der Inhaltsliste einer Box, eine Zeile je Adresszeile."
          :error="form.errors['organization.address']"
        />
        <UiInput
          v-model="form.organization.phone"
          label="Telefon"
          type="tel"
          hint="Zentrale Nummer, z. B. auf der Verleihseite."
          :error="form.errors['organization.phone']"
        />
      </div>
    </UiCard>

    <UiCard title="Rechtliches">
      <div class="grid gap-5 sm:grid-cols-3">
        <UiInput
          v-model="form.legal.imprint_url"
          label="Link zum Impressum"
          type="url"
          :error="form.errors['legal.imprint_url']"
        />
        <UiInput
          v-model="form.legal.privacy_url"
          label="Link zur Datenschutzerklärung"
          type="url"
          :error="form.errors['legal.privacy_url']"
        />
        <UiInput
          v-model="form.legal.contact_email"
          label="Kontaktadresse"
          type="email"
          :error="form.errors['legal.contact_email']"
        />
      </div>
    </UiCard>

    <UiCard
      title="Inventar"
      description="Neue Artikelnummern beginnen mit dem Präfix, z. B. FSR5-004218. Vergebene Nummern ändern sich nie."
    >
      <div class="grid gap-5 sm:grid-cols-3">
        <UiInput
          v-model="form.inventory.number_prefix"
          label="Präfix der Artikelnummern"
          :placeholder="defaultPrefix"
          hint="Leer lassen für den Kurznamen."
          :error="form.errors['inventory.number_prefix']"
        />
      </div>
      <div class="mt-5">
        <UiCheckbox
          v-model="form.inventory.product_lookup"
          label="Produktdaten per Barcode"
          description="Beim Erfassen lässt sich der Barcode der Verpackung scannen; Stocks fragt dann UPCitemdb, Open Food Facts und Open Products Facts nach Name und Bildern. Aus: keine Abfragen nach außen, die EAN lässt sich weiter eintippen."
        />
      </div>
    </UiCard>

    <UiCard
      title="Etiketten"
      description="Der Code auf jedem Etikett ist ein Kurzlink, z. B. https://stocks.example/s/FSR5-004218. Ändert sich die Adresse später, öffnet die Handykamera alte Etiketten nicht mehr; der Scan in Stocks liest weiter die Nummer."
    >
      <UiInput
        v-model="form.labels.base_url"
        label="Adresse auf Etiketten"
        type="url"
        :placeholder="appUrl"
        hint="Leer lassen für die Adresse der Instanz."
        :error="form.errors['labels.base_url']"
      />
    </UiCard>

    <div class="flex justify-end">
      <UiButton type="submit" :disabled="form.processing">Speichern</UiButton>
    </div>
  </form>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { useForm } from "@inertiajs/vue3";

const props = defineProps<{
  settings: Record<string, string | null>;
  branding: {
    logo: string | null;
    logo_light: string | null;
    favicon: string | null;
    print_logo: string | null;
    app_icon: string | null;
  };
  appUrl: string;
}>();

// logo and favicon have their own form, files can not be sent with PUT
type BrandingKind =
  "logo" | "logo_light" | "favicon" | "print_logo" | "app_icon";
const brandingKinds: {
  key: BrandingKind;
  label: string;
  accept: string;
  hint: string;
  // background of the preview
  background?: "dark" | "light";
}[] = [
  {
    key: "logo",
    label: "Logo für dunkle Hintergründe",
    accept: "image/png,image/jpeg,image/webp",
    hint: "Für den dunklen Modus, z. B. weiß. PNG, JPG oder WebP, höchstens 2 MB. Am besten quadratisch und mit durchsichtigem Hintergrund.",
    background: "dark",
  },
  {
    key: "logo_light",
    label: "Logo für helle Hintergründe",
    accept: "image/png,image/jpeg,image/webp",
    hint: "Für den hellen Modus. Solange kein eigenes Symbol für den Home-Bildschirm hochgeladen ist, steht es dort auf Weiß.",
    background: "light",
  },
  {
    key: "print_logo",
    label: "Druck-Logo",
    accept: "image/png,image/jpeg,image/webp",
    hint: "Für Papier und Etiketten: dunkel auf hellem Grund. Der Niimbot druckt nur Schwarz und Weiß. Ohne Druck-Logo gilt auf Papier das Logo für dunkle Hintergründe, in Mails das für helle.",
    background: "light",
  },
  {
    key: "favicon",
    label: "Symbol (Favicon)",
    accept: "image/png,image/x-icon,.ico",
    hint: "PNG oder ICO, quadratisch, höchstens 512 KB.",
  },
  {
    key: "app_icon",
    label: "Symbol für den Home-Bildschirm",
    accept: "image/png,image/jpeg,image/webp",
    hint: "Füllt das Symbol ganz, ohne Rand: quadratisch, mindestens 512 px. Je nach Gerät werden die Ecken rund abgeschnitten. Ein schon abgelegtes Symbol ändert sich erst, wenn die App neu zum Home-Bildschirm hinzugefügt wird.",
    background: "light",
  },
];
const brandingForm = useForm({
  logo: null as File | null,
  logo_light: null as File | null,
  favicon: null as File | null,
  print_logo: null as File | null,
  app_icon: null as File | null,
  remove: [] as BrandingKind[],
});
const previews = ref<Record<BrandingKind, string | null>>({
  logo: props.branding.logo,
  logo_light: props.branding.logo_light,
  favicon: props.branding.favicon,
  print_logo: props.branding.print_logo,
  app_icon: props.branding.app_icon,
});
const choose = (kind: BrandingKind, event: Event) => {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null;
  brandingForm[kind] = file;
  brandingForm.remove = brandingForm.remove.filter((entry) => entry !== kind);
  previews.value[kind] = file
    ? URL.createObjectURL(file)
    : props.branding[kind];
};
const removeImage = (kind: BrandingKind) => {
  brandingForm[kind] = null;
  brandingForm.remove = [...new Set([...brandingForm.remove, kind])];
  previews.value[kind] = null;
};
// a new favicon shows after reloading the page
const saveBranding = () =>
  brandingForm.post("/admin/settings/branding", {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => brandingForm.reset(),
  });

const value = (key: string) => props.settings[key] ?? "";

const form = useForm({
  organization: {
    name: value("organization.name"),
    short_name: value("organization.short_name"),
    accent_color: value("organization.accent_color"),
    address: value("organization.address"),
    phone: value("organization.phone"),
  },
  legal: {
    imprint_url: value("legal.imprint_url"),
    privacy_url: value("legal.privacy_url"),
    contact_email: value("legal.contact_email"),
  },
  inventory: {
    number_prefix: value("inventory.number_prefix"),
    product_lookup:
      (props.settings["inventory.product_lookup"] as unknown) !== false,
  },
  labels: {
    base_url: value("labels.base_url"),
  },
});

// prefix that is used while the field is empty, see App\Support\Inventory\ItemNumbers
const defaultPrefix = computed(
  () =>
    form.organization.short_name
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, "")
      .slice(0, 6) || "INV",
);

// empty fields are stored as null
const nullIfEmpty = (group: Record<string, string>) =>
  Object.fromEntries(
    Object.entries(group).map(([key, entry]) => [key, entry || null]),
  );

const submit = () =>
  form
    .transform((data) => ({
      ...data,
      legal: nullIfEmpty(data.legal),
      inventory: {
        number_prefix: data.inventory.number_prefix.toUpperCase() || null,
        product_lookup: data.inventory.product_lookup,
      },
      labels: nullIfEmpty(data.labels),
    }))
    .put("/admin/settings", { preserveScroll: true });
</script>
