import type { NiimbotAbstractClient } from "@mmote/niimbluelib";

/**
 * Niimbot printers directly from the browser with NiimBlueLib (Bluetooth or USB). Only Chrome and
 * Edge offer these interfaces (pc, Mac, Android); elsewhere the label is downloaded as image for the
 * Niimbot app. NiimBlueLib is loaded only when printing, it is big and unofficial (alpha).
 */

// one connection per browser tab; a bluetooth printer belongs to a device, not to an account
let client: NiimbotAbstractClient | null = null;

export type Connection = "bluetooth" | "serial";

/**
 * Ways of direct printing this browser offers.
 */
export const directConnections = (): Connection[] => [
  ...("bluetooth" in navigator ? (["bluetooth"] as const) : []),
  ...("serial" in navigator ? (["serial"] as const) : []),
];

export const isConnected = () => client?.isConnected() ?? false;

/**
 * Connect to a printer; the browser shows its own dialog to choose the device. model is the model
 * of the printer in Stocks (e.g. "B1"): with bluetooth only printers whose name starts with it are
 * offered.
 */
export const connect = async (
  connection: Connection,
  model: string | null = null,
) => {
  const lib = await import("@mmote/niimbluelib");
  await disconnect();

  if (connection === "serial") {
    client = new lib.NiimbotSerialClient();
    return (await client.connect()).deviceName ?? null;
  }

  // Own device dialog instead of the one of NiimBlueLib: it mixes about 20 name filters with a service
  // filter, and Bluefy on iPhone then offers no device at all. The printers do not announce their
  // service before connecting (B1: name "B1-…" only), so the dialog filters by name; the service
  // goes to optionalServices, without it the page may not use it after connecting.
  const known =
    model !== null && lib.modelsLibrary.some((entry) => entry.model === model);
  const prefixes = known
    ? [model]
    : [...new Set(lib.modelsLibrary.map((entry) => entry.model[0]))];
  const device = await navigator.bluetooth.requestDevice({
    filters: prefixes.map((namePrefix) => ({ namePrefix })),
    optionalServices: lib.BleDefaultConfiguration.SERVICES,
  });
  const bluetooth = new lib.NiimbotBluetoothClient();
  client = bluetooth;
  const info = await bluetooth.connect({ authorizedDevice: device });
  return info.deviceName ?? device.name ?? null;
};

export const disconnect = async () => {
  if (client?.isConnected()) {
    await client.disconnect();
  }
  client = null;
};

/**
 * Print labels one after the other. The canvases are already in the dots of the printer and black
 * and white (see prepareForNiimbot). Stops before the next label when the signal is aborted.
 */
export const printDirect = async (
  canvases: HTMLCanvasElement[],
  copies: number,
  printer: PrinterData,
  onProgress: (done: number, total: number) => void,
  signal: AbortSignal,
) => {
  if (!client?.isConnected()) {
    throw new Error("Der Drucker ist nicht verbunden.");
  }
  const lib = await import("@mmote/niimbluelib");
  const task = client.protocol.newPrintTask(
    (client.getPrintTaskType() ??
      printer.model?.print_task ??
      "B1") as Parameters<typeof client.protocol.newPrintTask>[0],
    {
      totalPages: canvases.length * copies,
      density: printer.settings.density ?? 3,
      labelType: lib.LabelType.WithGaps,
    },
  );
  const direction = client.getModelMetadata()?.printDirection ?? "top";

  try {
    await task.printInit();
    for (const [index, canvas] of canvases.entries()) {
      if (signal.aborted) {
        break;
      }
      await task.printPage(
        lib.ImageEncoder.encodeCanvas(
          canvas,
          lib.PageColorType.SingleColor,
          direction,
        ),
        copies,
      );
      await task.waitForPageFinished();
      onProgress(index + 1, canvases.length);
    }
    await task.waitForFinished();
  } finally {
    await task.printEnd();
  }
};
