import { drawLabel, toMonochrome } from "../render";
import { zip } from "../zip";
import { labelsPdf, type PdfPage } from "./pdf";

/**
 * Printing per driver of the printer (App\Enums\PrinterDriver). Every driver takes labels drawn
 * by render.ts; a new driver (Brother, Zebra) adds a case here and its own file.
 *
 * - paper: print dialog of the browser, one label per page or an A4 sheet
 * - niimbot: directly by Bluetooth or USB (niimbot.ts), otherwise as png in the dots of the printer
 */

// resolution of paper prints, about 300 dpi
const PAPER_PX_PER_MM = 12;

export type PrintLabel = {
  template: { width_mm: number; height_mm: number; elements: LabelElement[] };
  data: LabelData;
};

/**
 * Pixels per mm the printer prints with.
 */
export const pxPerMmOf = (printer: PrinterData) =>
  printer.driver === "niimbot"
    ? (printer.model?.dpi ?? 203) / 25.4
    : PAPER_PX_PER_MM;

/**
 * Draw a label as the printer gets it. For niimbot printers in its dots, turned and shifted by
 * the settings of the printer and in hard black and white; "crop" cuts it to the print head (direct
 * print), without it the image keeps the full label size for the Niimbot app (50 × 30 mm → 400 × 240).
 */
export const renderForPrinter = (
  label: PrintLabel,
  printer: PrinterData,
  images: Map<string, HTMLImageElement | null>,
  logoUrl: string | null,
  crop = false,
) => {
  const k = pxPerMmOf(printer);
  const canvas = document.createElement("canvas");
  const problems = drawLabel(canvas, label.template, label.data, {
    pxPerMm: k,
    images,
    logoUrl,
  });

  if (printer.driver !== "niimbot") {
    return { canvas, problems };
  }

  const prepared = transform(
    canvas,
    printer.settings.rotation ?? 0,
    (printer.settings.offset_x ?? 0) * k,
    (printer.settings.offset_y ?? 0) * k,
  );
  const head = Math.round((printer.model?.max_width_mm ?? Infinity) * k);
  const result =
    crop && prepared.width > head ? cropMiddle(prepared, head) : prepared;
  toMonochrome(result);

  return { canvas: result, problems };
};

/**
 * Turn a canvas by 0, 90, 180 or 270 degrees and shift its content (white where nothing is).
 */
const transform = (
  source: HTMLCanvasElement,
  rotation: number,
  offsetX: number,
  offsetY: number,
) => {
  const turned = rotation === 90 || rotation === 270;
  const canvas = document.createElement("canvas");
  canvas.width = turned ? source.height : source.width;
  canvas.height = turned ? source.width : source.height;

  const context = canvas.getContext("2d", { willReadFrequently: true })!;
  context.fillStyle = "#fff";
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.translate(canvas.width / 2 + offsetX, canvas.height / 2 + offsetY);
  context.rotate((rotation * Math.PI) / 180);
  context.drawImage(source, -source.width / 2, -source.height / 2);

  return canvas;
};

/**
 * Cut a canvas to the given width around its middle.
 */
const cropMiddle = (source: HTMLCanvasElement, width: number) => {
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = source.height;
  canvas
    .getContext("2d", { willReadFrequently: true })!
    .drawImage(source, -Math.round((source.width - width) / 2), 0);
  return canvas;
};

/**
 * Labels on paper pages, by the settings of the printer: one label per page in its own size, or
 * labels in a grid on A4 sheets. Shared by the print dialog and the pdf.
 */
const paperPages = (
  canvases: {
    canvas: HTMLCanvasElement;
    width_mm: number;
    height_mm: number;
  }[],
  copies: number,
  printer: PrinterData,
): PdfPage[] => {
  const labels = canvases.flatMap((entry) =>
    Array.from({ length: copies }, () => entry),
  );
  const settings = printer.settings;

  if (settings.page === "sheet") {
    const columns = settings.columns ?? 2;
    const perSheet = columns * (settings.rows ?? 8);
    return Array.from(
      { length: Math.ceil(labels.length / perSheet) },
      (_, sheet) => ({
        width_mm: 210,
        height_mm: 297,
        images: labels
          .slice(sheet * perSheet, (sheet + 1) * perSheet)
          .map((entry, index) => ({
            canvas: entry.canvas,
            width_mm: entry.width_mm,
            height_mm: entry.height_mm,
            left_mm:
              (settings.margin_left ?? 0) +
              (index % columns) * (entry.width_mm + (settings.gap_x ?? 0)),
            top_mm:
              (settings.margin_top ?? 0) +
              Math.floor(index / columns) *
                (entry.height_mm + (settings.gap_y ?? 0)),
          })),
      }),
    );
  }

  // a label printer at the pc gets one page per label in the size of the first label
  return labels.map((entry) => ({
    width_mm: labels[0].width_mm,
    height_mm: labels[0].height_mm,
    images: [
      {
        canvas: entry.canvas,
        width_mm: entry.width_mm,
        height_mm: entry.height_mm,
        left_mm: 0,
        top_mm: 0,
      },
    ],
  }));
};

/**
 * Labels as pdf, for the home screen app on iOS where there is no print dialog: it is shown in the
 * file viewer and printed through the share sheet.
 */
export const paperPdf = (
  canvases: {
    canvas: HTMLCanvasElement;
    width_mm: number;
    height_mm: number;
  }[],
  copies: number,
  printer: PrinterData,
) =>
  labelsPdf(
    paperPages(canvases, copies, printer),
    canvases.length === 1 && copies === 1 ? "Etikett.pdf" : "Etiketten.pdf",
  );

/**
 * Print on paper with the print dialog of the browser: one label per page in its own size, or
 * labels in a grid on A4 sheets (settings of the printer).
 */
export const printPaper = async (
  canvases: {
    canvas: HTMLCanvasElement;
    width_mm: number;
    height_mm: number;
  }[],
  copies: number,
  printer: PrinterData,
) => {
  const pages = paperPages(canvases, copies, printer);
  const sheet = printer.settings.page === "sheet";
  const page = sheet
    ? "size: A4; margin: 0;"
    : `size: ${pages[0].width_mm}mm ${pages[0].height_mm}mm; margin: 0;`;
  const body = pages
    .map(
      (entry) =>
        `<section>${entry.images
          .map(
            (image) =>
              `<img src="${image.canvas.toDataURL("image/png")}" style="width:${image.width_mm}mm;height:${image.height_mm}mm;${
                sheet
                  ? `position:absolute;left:${image.left_mm}mm;top:${image.top_mm}mm;`
                  : ""
              }">`,
          )
          .join("")}</section>`,
    )
    .join("");

  // printed from the page itself: Safari on iOS and some Android browsers ignore print() in a
  // frame and print the whole page instead. While printing only the labels are shown.
  document.getElementById("stocks-print")?.remove();
  document.getElementById("stocks-print-style")?.remove();
  const style = document.createElement("style");
  style.id = "stocks-print-style";
  style.textContent = `
    #stocks-print { display: none; }
    @media print {
      @page { ${page} }
      html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
      body > *:not(#stocks-print) { display: none !important; }
      #stocks-print { display: block; }
      #stocks-print section { position: relative; page-break-after: always; break-after: page; ${sheet ? "width: 210mm; height: 297mm;" : ""} overflow: hidden; }
      #stocks-print section:last-child { page-break-after: auto; break-after: auto; }
      #stocks-print img { display: block; }
    }`;
  const container = document.createElement("div");
  container.id = "stocks-print";
  container.innerHTML = body;
  document.head.append(style);
  document.body.append(container);

  await Promise.all(
    [...container.querySelectorAll("img")].map((element) =>
      element.decode().catch(() => undefined),
    ),
  );

  // removed only when the page is used again: Safari on iOS reports afterprint before its print
  // sheet has taken the page, and some browsers never report it. Until then the container is
  // hidden on screen; the next print replaces it.
  const cleanUp = () => {
    container.remove();
    style.remove();
    window.removeEventListener("pointerdown", cleanUp);
    window.removeEventListener("keydown", cleanUp);
  };
  window.print();
  setTimeout(() => {
    window.addEventListener("pointerdown", cleanUp, { once: true });
    window.addEventListener("keydown", cleanUp, { once: true });
  }, 1000);
};

/**
 * Labels as png files, shown in the file viewer on iPhone and iPad (home screen app, Bluefy), where
 * downloads do not work; its share button hands them on (e.g. save to photos for the Niimbot app).
 */
export const imageFiles = (
  canvases: { canvas: HTMLCanvasElement; name: string }[],
) =>
  Promise.all(
    canvases.map(
      (entry) =>
        new Promise<File>((resolve, reject) =>
          entry.canvas.toBlob(
            (blob) =>
              blob
                ? resolve(
                    new File([blob], `${entry.name}.png`, {
                      type: "image/png",
                    }),
                  )
                : reject(new Error("Bild fehlgeschlagen")),
            "image/png",
          ),
        ),
    ),
  );

/**
 * Download labels as png, several in a zip file.
 */
export const downloadImages = async (
  canvases: { canvas: HTMLCanvasElement; name: string }[],
) => {
  const blobOf = (canvas: HTMLCanvasElement) =>
    new Promise<Blob>((resolve) =>
      canvas.toBlob((blob) => resolve(blob!), "image/png"),
    );

  const save = (blob: Blob, name: string) => {
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 10_000);
  };

  if (canvases.length === 1) {
    save(await blobOf(canvases[0].canvas), `${canvases[0].name}.png`);
    return;
  }

  const files = await Promise.all(
    canvases.map(async (entry) => ({
      name: `${entry.name}.png`,
      data: new Uint8Array(await (await blobOf(entry.canvas)).arrayBuffer()),
    })),
  );
  save(zip(files), "Etiketten.zip");
};
