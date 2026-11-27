import { ref, type Ref } from "vue";
import { loadImages } from "../labels/render";
import {
  downloadImages,
  imageFiles,
  paperPdf,
  printPaper,
  renderForPrinter,
  type PrintLabel,
} from "../labels/drivers";
import * as niimbot from "../labels/drivers/niimbot";
import useFileViewer, { usesFileViewer } from "./useFileViewer";

/**
 * The three ways to print labels, used by the print page and the test print of the editor: paper
 * (print dialog), Niimbot directly by bluetooth or usb, or as image for the Niimbot app. What is
 * printed comes from labels(); afterPrint runs after a successful print (the print page notes the
 * labels as printed, the test print does not). For a direct print it gets how many of the labels
 * were printed (fewer after "Abbrechen"), otherwise null.
 */
export default function (
  printer: Ref<PrinterData | null>,
  logoUrl: () => string | null,
  labels: () => (PrintLabel & { name: string })[],
  copies: () => number,
  afterPrint: (done: number | null) => void = () => {},
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

  // labels printed by the last direct print, null for paper and images
  let lastDone: number | null = null;
  const run = async (job: () => Promise<string>) => {
    if (!labels().length) {
      printError.value = "Wähle mindestens ein Etikett aus.";
      return;
    }
    busy.value = true;
    printError.value = null;
    printed.value = null;
    lastDone = null;
    try {
      printed.value = await job();
      afterPrint(lastDone);
    } catch (error) {
      printError.value = error instanceof Error ? error.message : String(error);
    } finally {
      busy.value = false;
    }
  };

  // the home screen app and Bluefy on iOS have no print dialog and no downloads: the labels go to
  // the file viewer (pdf, or images), printed or handed on through its share button
  const { showFile, showFiles } = useFileViewer();
  const printOnPaper = () =>
    run(async () => {
      if (usesFileViewer()) {
        showFile(await paperPdf(await render(false), copies(), printer.value!));
        return "Vorschau geöffnet. Unten links über Teilen → Drucken.";
      }
      await printPaper(await render(false), copies(), printer.value!);
      return "Druckdialog geöffnet.";
    });

  const downloadPng = () =>
    run(async () => {
      if (usesFileViewer()) {
        const images = await imageFiles(await render(false));
        showFiles(
          images,
          images.length === 1 ? images[0].name : `${images.length} Etiketten`,
        );
        return "Vorschau geöffnet. Über Teilen → „Bild sichern“, dann in der Niimbot-App öffnen und drucken.";
      }
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
      deviceName.value = await niimbot.connect(
        connection,
        printer.value?.model?.name ?? null,
      );
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
      lastDone = 0;
      try {
        await niimbot.printDirect(
          canvases.map((entry) => entry.canvas),
          copies(),
          printer.value!,
          (done, total) => {
            progress.value = { done, total };
            lastDone = done;
          },
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
    // labels of the last direct print that came out, also when it stopped with an error
    printedCount: () => lastDone,
  };
}
