export {};

declare global {
  /**
   * Column of components/ui/Table.vue. The cell shows value(row) as text, or the slot
   * "cell-<name>" of the table for anything else (links, badges, buttons).
   */
  interface TableColumn {
    name: string;
    header: string;
    value?: (row: any) => string | number | null | undefined;
    // sort key of the table class on the server (app/Support/Table); without it the column can not be sorted
    sortKey?: string;
  }

  /**
   * Row of the item list (app/Support/Table/ItemsTable.php, rowFor()).
   */
  interface ItemRow {
    id: number;
    number: string;
    name: string;
    category: string | null;
    quantity: number;
    unit: string | null;
    kind: "single" | "bulk";
    is_container: boolean;
    condition: string;
    condition_is_defect: boolean;
    place: {
      type: string;
      id: number;
      code: string;
      name: string | null;
    } | null;
    away_from_home: boolean;
    retired: boolean;
    photo_url: string | null;
    label_printed_at: string | null;
    // active items directly in the box
    contents_count: number;
    badges: { text: string; variant: string }[];
    _version?: number;
  }
}
