<template>
  <UiPageHeader :title="contact.name" :description="contact.affiliation">
    <UiButton variant="secondary" icon="pen" @click="editing = true"
      >Bearbeiten</UiButton
    >
    <UiButton
      v-if="canDelete"
      variant="ghost"
      icon="trash"
      @click="deleteOpen = true"
      >Löschen</UiButton
    >
  </UiPageHeader>

  <UiAlert v-if="page.props.errors?.contact" type="error" class="mb-4">{{
    page.props.errors.contact
  }}</UiAlert>
  <UiAlert v-if="contact.missing?.length" type="warning" class="mb-4">
    Für eine Buchung als verantwortliche Person fehlen noch:
    {{ contact.missing.join(", ") }}.
  </UiAlert>

  <div class="grid gap-6 lg:grid-cols-3">
    <UiCard title="Kontaktdaten">
      <dl class="space-y-3 text-sm">
        <div>
          <dt class="text-ink-muted">E-Mail</dt>
          <dd>
            <UiContactLink kind="email" :value="contact.email" />
            <template v-if="!contact.email">–</template>
          </dd>
        </div>
        <div>
          <dt class="text-ink-muted">Telefon</dt>
          <dd>
            <UiContactLink kind="phone" :value="contact.phone" />
            <template v-if="!contact.phone">–</template>
          </dd>
        </div>
        <div>
          <dt class="text-ink-muted">Anschrift</dt>
          <dd>{{ contact.address || "–" }}</dd>
        </div>
        <div v-if="contact.notes">
          <dt class="text-ink-muted">Notiz</dt>
          <dd class="whitespace-pre-line">{{ contact.notes }}</dd>
        </div>
      </dl>
    </UiCard>
    <UiCard title="Buchungen" class="lg:col-span-2" flush>
      <ul v-if="loans.length" class="divide-y divide-line">
        <li
          v-for="loan in loans"
          :key="loan.id"
          class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 sm:px-6"
        >
          <span>
            <UiLink :href="`/loans/${loan.id}`" class="font-medium">{{
              loan.title
            }}</UiLink>
            <span class="block text-xs text-ink-muted"
              >{{ formatDateTime(loan.pickup_at) }} bis
              {{ formatDateTime(loan.return_at) }}</span
            >
          </span>
          <LoansStatusBadge :loan="loan" />
        </li>
      </ul>
      <UiEmptyState v-else icon="calendar-check" title="Noch keine Buchungen" />
    </UiCard>
  </div>

  <LoansContactForm
    v-if="editing"
    :contact="contact"
    :councils="councils"
    @close="editing = false"
    @saved="saved"
  />
  <UiConfirmModal
    v-if="deleteOpen"
    title="Kontakt löschen"
    :message="deleteMessage"
    @close="deleteOpen = false"
    @confirm="router.delete(`/loans/contacts/${contact.id}`)"
  />
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { formatDateTime, type Contact, type LoanSummary } from "../../http";
import LoansContactForm from "../../components/ContactForm.vue";
import LoansStatusBadge from "../../components/StatusBadge.vue";

/**
 * A contact with its loans. A contact without loans is deleted with "loans.manage"; with loans it
 * needs "loans.contacts.delete" and all of them finished, the name stays at the loans.
 */
const props = defineProps<{
  contact: Contact;
  loans: LoanSummary[];
  councils: Option[];
  deletion: { loans: number; events: number; open: string[] };
}>();

const page = usePage<SharedProps>();
const { can } = usePermissions();
const hasLoans = computed(
  () => props.deletion.loans > 0 || props.deletion.events > 0,
);
// with open loans the button stays, the server says which ones are open
const canDelete = computed(
  () => !hasLoans.value || can("loans.contacts.delete"),
);
const deleteMessage = computed(() => {
  if (props.deletion.open.length) {
    return `${props.contact.name} hat noch offene Buchungen (${props.deletion.open.join(", ")}). Erst wenn sie abgeschlossen oder storniert sind, lässt sich der Kontakt löschen.`;
  }
  if (!hasLoans.value) {
    return `${props.contact.name} wird gelöscht.`;
  }
  const kept = [
    props.deletion.loans
      ? `${props.deletion.loans} ${props.deletion.loans === 1 ? "Buchung" : "Buchungen"}`
      : null,
    props.deletion.events
      ? `${props.deletion.events} ${props.deletion.events === 1 ? "Veranstaltung" : "Veranstaltungen"}`
      : null,
  ]
    .filter(Boolean)
    .join(" und ");
  return `${props.contact.name} wird gelöscht. ${kept} behalten den Namen als Text. E-Mail, Telefon, Anschrift und Notiz gehen verloren, auch im Protokoll. Die Verleihseiten schicken der Person keine Push-Nachrichten mehr.`;
});
const editing = ref(false);
const deleteOpen = ref(false);
const saved = () => {
  editing.value = false;
  router.reload();
};
</script>
