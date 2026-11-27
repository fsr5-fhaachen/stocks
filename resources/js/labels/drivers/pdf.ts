/**
 * Small pdf writer for printed labels: every label as jpeg image at its place in millimetres. Used
 * where the browser has no print dialog (home screen app on iOS); the pdf goes to the file viewer,
 * whose share sheet prints it. No text, no fonts, so a few objects are enough.
 */

export type PdfPage = {
  width_mm: number;
  height_mm: number;
  images: {
    canvas: HTMLCanvasElement;
    left_mm: number;
    top_mm: number;
    width_mm: number;
    height_mm: number;
  }[];
};

const POINTS_PER_MM = 72 / 25.4;
const encoder = new TextEncoder();

/**
 * Jpeg bytes of a canvas, on a white background (labels may be transparent where nothing is drawn).
 */
const jpegOf = async (canvas: HTMLCanvasElement) => {
  const flat = document.createElement("canvas");
  flat.width = canvas.width;
  flat.height = canvas.height;
  const context = flat.getContext("2d")!;
  context.fillStyle = "#fff";
  context.fillRect(0, 0, flat.width, flat.height);
  context.drawImage(canvas, 0, 0);
  const blob = await new Promise<Blob>((resolve, reject) =>
    flat.toBlob(
      (result) =>
        result ? resolve(result) : reject(new Error("Bild fehlgeschlagen")),
      "image/jpeg",
      // high quality: codes must stay readable
      0.95,
    ),
  );
  flat.width = 0;
  flat.height = 0;
  return new Uint8Array(await blob.arrayBuffer());
};

const number = (value: number) => value.toFixed(2);

/**
 * Write the pdf. Each canvas is embedded once, also when it appears on several pages (copies).
 */
export const labelsPdf = async (pages: PdfPage[], name: string) => {
  const chunks: Uint8Array[] = [];
  const offsets: number[] = [];
  let length = 0;
  const write = (part: string | Uint8Array) => {
    const bytes = typeof part === "string" ? encoder.encode(part) : part;
    chunks.push(bytes);
    length += bytes.length;
  };
  // object numbers: 1 catalog, 2 page tree, then images, then per page its page and content
  const object = (id: number, body: (string | Uint8Array)[]) => {
    offsets[id] = length;
    write(`${id} 0 obj\n`);
    body.forEach(write);
    write("\nendobj\n");
  };

  const canvases = [
    ...new Set(pages.flatMap((page) => page.images.map((i) => i.canvas))),
  ];
  const imageId = new Map(canvases.map((canvas, index) => [canvas, 3 + index]));
  const firstPageId = 3 + canvases.length;

  write("%PDF-1.4\n%âãÏÓ\n");
  object(1, ["<< /Type /Catalog /Pages 2 0 R >>"]);
  object(2, [
    `<< /Type /Pages /Count ${pages.length} /Kids [${pages
      .map((_, index) => `${firstPageId + index * 2} 0 R`)
      .join(" ")}] >>`,
  ]);

  for (const canvas of canvases) {
    const jpeg = await jpegOf(canvas);
    object(imageId.get(canvas)!, [
      `<< /Type /XObject /Subtype /Image /Width ${canvas.width} /Height ${canvas.height} ` +
        `/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpeg.length} >>\nstream\n`,
      jpeg,
      "\nendstream",
    ]);
  }

  pages.forEach((page, index) => {
    const pageId = firstPageId + index * 2;
    const width = page.width_mm * POINTS_PER_MM;
    const height = page.height_mm * POINTS_PER_MM;
    // pdf counts from the bottom left, labels are placed from the top left
    const drawing = page.images
      .map((image) => {
        const w = image.width_mm * POINTS_PER_MM;
        const h = image.height_mm * POINTS_PER_MM;
        const x = image.left_mm * POINTS_PER_MM;
        const y = height - image.top_mm * POINTS_PER_MM - h;
        return `q ${number(w)} 0 0 ${number(h)} ${number(x)} ${number(y)} cm /I${imageId.get(image.canvas)} Do Q`;
      })
      .join("\n");
    const resources = [
      ...new Set(page.images.map((i) => imageId.get(i.canvas))),
    ]
      .map((id) => `/I${id} ${id} 0 R`)
      .join(" ");
    object(pageId, [
      `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${number(width)} ${number(height)}] ` +
        `/Resources << /XObject << ${resources} >> >> /Contents ${pageId + 1} 0 R >>`,
    ]);
    const stream = encoder.encode(drawing);
    object(pageId + 1, [
      `<< /Length ${stream.length} >>\nstream\n`,
      stream,
      "\nendstream",
    ]);
  });

  const xref = length;
  const count = firstPageId + pages.length * 2;
  write(`xref\n0 ${count}\n0000000000 65535 f \n`);
  for (let id = 1; id < count; id++) {
    write(`${String(offsets[id]).padStart(10, "0")} 00000 n \n`);
  }
  write(
    `trailer\n<< /Size ${count} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`,
  );

  return new File(chunks as BlobPart[], name, { type: "application/pdf" });
};
