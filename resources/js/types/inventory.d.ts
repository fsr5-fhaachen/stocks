export {};

declare global {
  /**
   * Place of an item: a location or a box (App\Models\Item::placeSummary).
   */
  interface Place {
    type: "location" | "container";
    id: number;
    code: string;
    name: string | null;
  }

  /**
   * Notice of a module about an item (App\Support\Extensions\ItemInfoProvider), e.g.
   * "ausgegeben an … bis …". A lock stops moving and retiring.
   */
  interface ItemNotice {
    text: string;
    badge: string | null;
    variant: "info" | "warning" | "danger";
    href: string | null;
    lock: boolean;
    actions: { title: string; href: string; icon: string }[];
  }

  /**
   * Stock of a bulk item at one place (App\Models\ItemStock::summary); place null is the virtual
   * compartment "Eingang".
   */
  interface StockEntry {
    id: number;
    // item_stocks.place_key, sent back as "from"
    key: string;
    quantity: number;
    // "Sollmenge"
    target_quantity: number | null;
    short: boolean;
    place: Place | null;
  }

  /**
   * Pieces of a bulk item in a box or at a location (App\Support\Inventory\BoxContents::fields).
   */
  interface StockMark {
    key: string;
    target_quantity: number | null;
    short: boolean;
  }

  /**
   * Item in the contents tree of a box. Bulk pieces in the box come with "stock" and the quantity
   * in this box.
   */
  interface ContentsItem {
    key: string;
    stock: StockMark | null;
    id: number;
    number: string;
    name: string;
    quantity: number;
    unit: string | null;
    kind: "single" | "bulk";
    is_container: boolean;
    // bulk item whose unit is a consumable
    consumable?: boolean;
    // small preview of the first photo
    photo_url: string | null;
    contents: ContentsItem[];
  }
}
