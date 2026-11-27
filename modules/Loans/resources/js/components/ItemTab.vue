<template>
  <UiCard title="Verleih" flush>
    <div v-if="data" class="space-y-4 py-2">
      <LoansTimeline
        :rows="[
          {
            id: item.id,
            number: item.number,
            name: item.name,
            loans: data.loans,
          },
        ]"
        :from="new Date(data.from)"
        :days="56"
        :link-items="false"
        :labels="false"
        @open="open"
      />
      <!-- pieces of a bulk item that are not here: with which loan, in which state, with notes -->
      <div v-if="data.pieces.length" class="px-4 sm:px-6">
        <h3 class="mb-2 text-sm font-semibold">Gerade nicht hier</h3>
        <ul class="divide-y divide-line rounded-md ring-1 ring-line">
          <li
            v-for="(piece, index) in data.pieces"
            :key="index"
            class="space-y-1 px-3 py-2"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span>
                <strong>{{ piece.quantity }} {{ item.unit }}</strong>
                ·
                <UiLink :href="`/loans/${piece.loan.id}`">{{
                  piece.loan.number
                }}</UiLink>
                <template v-if="piece.loan.responsible">
                  · {{ piece.loan.responsible }}</template
                >
              </span>
              <UiBadge :variant="piece.variant">{{ piece.state }}</UiBadge>
            </div>
            <p
              v-for="note in piece.notes"
              :key="note"
              class="text-sm text-ink-muted"
            >
              {{ note }}
            </p>
          </li>
        </ul>
      </div>
      <ul v-if="data.loans.length" class="divide-y divide-line">
        <li
          v-for="loan in data.loans"
          :key="loan.id"
          class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 sm:px-6"
        >
          <div>
            <UiLink :href="`/loans/${loan.id}`" class="font-medium">{{
              loan.title
            }}</UiLink>
            <p class="text-sm text-ink-muted">
              {{ formatDateTime(loan.pickup_at) }} bis
              {{ formatDateTime(loan.return_at) }}
              <template v-if="loan.responsible">
                · {{ loan.responsible }}</template
              >
            </p>
          </div>
          <LoansStatusBadge :loan="loan" />
        </li>
      </ul>
      <UiEmptyState v-else icon="calendar-check" title="Noch nie verliehen" />
    </div>
    <div v-else class="m-4 h-24 animate-pulse rounded bg-surface-muted" />
  </UiCard>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { formatDateTime, getJson, type LoanSummary } from "../http";
import LoansTimeline from "./Timeline.vue";
import LoansStatusBadge from "./StatusBadge.vue";

/**
 * Tab "Verleih" on the page of an item: its timeline of the coming weeks and all its loans. Of a
 * bulk item first the pieces that are not here (with a loan, not put away, missing) and their notes.
 */
const props = defineProps<{
  item: { id: number; number: string; name: string; unit?: string | null };
}>();

type Piece = {
  loan: LoanSummary;
  quantity: number;
  state: string;
  variant: "neutral" | "warning" | "danger";
  notes: string[];
};

const data = ref<{
  from: string;
  to: string;
  loans: LoanSummary[];
  pieces: Piece[];
} | null>(null);
onMounted(async () => {
  data.value = await getJson(`/loans/items/${props.item.id}`);
});

const open = (loan: LoanSummary) => router.visit(`/loans/${loan.id}`);
</script>
