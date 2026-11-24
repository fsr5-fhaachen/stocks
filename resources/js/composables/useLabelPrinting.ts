import { ref, type Ref } from "vue";
import { loadImages } from "../labels/render";
import {
  downloadImages,
  printPaper,
  renderForPrinter,
  type PrintLabel,
} from "../labels/drivers";
import * as niimbot from "../labels/drivers/niimbot";

/**
 * The three ways to print labels, used by the print page and the test print of the editor: paper
 * (print dialog), Niimbot directly by bluetooth or usb, or as image for the Niimbot app. What is
 * printed comes from labels(); afterPrint runs after a successful print (the print page notes the
 * labels as printed, the test print does not).
 */
export default function (
  printer: Ref<PrinterData | null>,
  logoUrl: () => string | null,
  labels: () => (PrintLabel & { name: string })[],
  copies: () => number,
  afterPrint: () => void = () => {},
) {
  const { surface } = useSurface();

  const busy = ref(false);
  const printError = ref<string | null>(null);
  const printed = ref<string | null>(null);

  const render = async (crop: boolean) => {
    const list = labels();
    const images = await loadImages(
      list.map((label) => label.template),
      list.map((label) => label.data),
      logoUrl(),
    );
    return list.map((label) => ({
      ...renderForPrinter(label, printer.value!, images, logoUrl(), crop),
      name: label.name,
      width_mm: label.template.width_mm,
      height_mm: label.template.height_mm,
    }));
  };

  const run = async (job: () => Promise<string>) => {
    if (!labels().length) {
      printError.value = "Wähle mindestens ein Etikett aus.";
      return;
    }
    busy.value = true;
    printError.value = null;
    printed.value = null;
    try {
      printed.value = await job();
      afterPrint();
    } catch (error) {
      printError.value = error instanceof Error ? error.message : String(error);
    } finally {
      busy.value = false;
    }
  };

  const printOnPaper = () =>
    run(async () => {
      await printPaper(await render(false), copies(), printer.value!);
      return "An den Druckdialog übergeben.";
    });

  const downloadPng = () =>
    run(async () => {
      await downloadImages(await render(false));
      return "Heruntergeladen. In der Niimbot-App das Bild öffnen und drucken.";
    });

  // niimbot directly by bluetooth or usb
  // the platform of a kiosk device can rule it out (iPad: Safari has neither bluetooth nor usb)
  const connections =
    surface.value?.direct_print === false ? [] : niimbot.directConnections();
  const connected = ref(niimbot.isConnected());
  const deviceName = ref<string | null>(null);
  const connectPrinter = async (connection: niimbot.Connection) => {
    busy.value = true;
    printError.value = null;
    try {
      deviceName.value = await niimbot.connect(connection);
      connected.value = true;
    } catch (error) {
      printError.value = `Verbinden ging nicht: ${error instanceof Error ? error.message : error}`;
    } finally {
      busy.value = false;
    }
  };

  const progress = ref<{ done: number; total: number } | null>(null);
  let abort: AbortController | null = null;
  const printNiimbot = () =>
    run(async () => {
      const canvases = await render(true);
      abort = new AbortController();
      progress.value = { done: 0, total: canvases.length };
      try {
        await niimbot.printDirect(
          canvases.map((entry) => entry.canvas),
          copies(),
          printer.value!,
          (done, total) => (progress.value = { done, total }),
          abort.signal,
        );
        return abort.signal.aborted
          ? `Abgebrochen nach ${progress.value.done} Etiketten.`
          : "Gedruckt.";
      } finally {
        progress.value = null;
        connected.value = niimbot.isConnected();
      }
    });
  const cancel = () => abort?.abort();

  return {
    busy,
    printError,
    printed,
    printOnPaper,
    downloadPng,
    connections,
    connected,
    deviceName,
    connectPrinter,
    progress,
    printNiimbot,
    cancel,
  };
}
