<template>
  <UiPageHeader
    title="Zur Buchung hinzufügen"
    :description="`${item.number} ${item.name}`"
  >
    <UiButton icon="plus" :href="`/loans/create?item=${item.id}`"
      >Neue Buchung damit</UiButton
    >
  </UiPageHeader>

  <UiAlert v-if="problem" type="error" class="mb-4">{{ problem }}</UiAlert>
  <!-- bulk item at several places: the issue takes the pieces from the chosen one -->
  <UiCard v-if="sourceOptions.length > 1" class="mb-4">
    <UiSelect
      v-model="from"
      label="Von"
      :options="sourceOptions"
      :placeholder="null"
      hint="Die Buchung reserviert nur. Die Stücke verlassen diese Stelle erst bei der Ausgabe."
    />
  </UiCard>
  <UiAlert v-if="errorMessages.length" type="error" class="mb-4">{{
    errorMessages.join(" ")
  }}</UiAlert>

  <UiCard
    title="Offene Buchungen"
    description="Entwürfe und bestätigte Buchungen, die noch nicht vorbei sind."
    flush
  >
    <ul v-if="loans.length" class="divide-y divide-line">
      <li
        v-for="loan in loans"
        :key="loan.id"
        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6"
      >
        <div class="min-w-0">
          <UiLink :href="`/loans/${loan.id}`" class="font-medium">{{
            loan.title
          }}</UiLink>
          <p class="text-sm text-ink-muted">
            {{ formatDateTime(loan.pickup_at) }} bis
            {{ formatDateTime(loan.return_at) }} ·
            <LoansStatusBadge :loan="loan" />
          </p>
          <p v-if="loan.contains" class="text-sm">Schon in dieser Buchung.</p>
          <p
            v-else-if="loan.check.conflicts.length"
            class="text-sm text-red-600"
          >
            In dieser Zeit gebucht:
            {{ loan.check.conflicts.map((other) => other.number).join(", ") }}
          </p>
        </div>
        <div class="flex items-center gap-2">
          <input
            v-if="item.kind === 'bulk'"
            v-model="quantities[loan.id]"
            type="number"
            min="1"
            :max="item.quantity"
            inputmode="numeric"
            aria-label="Menge"
            class="w-20 rounded-md border-0 bg-surface py-1.5 text-ink ring-1 ring-inset ring-line"
          />
          <UiButton
            size="sm"
            icon="plus"
            :disabled="
              !!problem ||
              (loan.contains && item.kind !== 'bulk') ||
              (loan.status === 'confirmed' && loan.check.blocked)
            "
            @click="add(loan.id)"
          >
            Hinzufügen
          </UiButton>
        </div>
      </li>
    </ul>
    <UiEmptyState
      v-else
      icon="calendar-check"
      title="Keine offene Buchung"
      description="Leg eine neue Buchung mit diesem Gegenstand an."
    />
  </UiCard>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { formatDateTime, type LoanSummary } from "../../http";
import LoansStatusBadge from "../../components/StatusBadge.vue";

/**
 * "Zur Buchung hinzufügen" from the scan tab: choose an open loan or start a new one.
 */
const props = defineProps<{
  item: {
    id: number;
    number: string;
    name: string;
    quantity: number;
    unit: string | null;
    kind: string;
    stocks: StockEntry[];
  };
  from: string | null;
  problem: string | null;
  loans: (LoanSummary & {
    contains: boolean;
    check: { blocked: boolean; conflicts: { number: string }[] };
  })[];
}>();

const page = usePage<SharedProps>();
const errorMessages = computed(() =>
  ["item", "quantity", "from"]
    .map((key) => page.props.errors?.[key])
    .filter(Boolean),
);
const quantities = reactive<Record<number, number>>({});
const sourceOptions = computed<Option[]>(() =>
  props.item.stocks.map((stock) => ({
    value: stock.key,
    label: `${stock.place ? stock.place.code : "Eingang"} (${stock.quantity} ${props.item.unit ?? ""})`,
  })),
);
const from = ref<string | null>(
  props.from && props.item.stocks.some((stock) => stock.key === props.from)
    ? props.from
    : null,
);
if (from.value === null && sourceOptions.value.length > 1) {
  from.value = String(sourceOptions.value[0].value);
}
const add = (loanId: number) =>
  router.post(`/loans/${loanId}/positions`, {
    item_id: props.item.id,
    quantity: quantities[loanId] ?? 1,
    from: props.item.kind === "bulk" ? from.value : null,
  });
</script>
