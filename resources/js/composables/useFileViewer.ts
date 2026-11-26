import { ref } from "vue";

/**
 * Own viewer for pdfs and images on iPhone and iPad where the browser handles files badly: the
 * home screen app and bluetooth browsers like Bluefy (see usesFileViewer).
 *
 * In the home screen app iOS opens a pdf in a frame without share button, ignores window.print()
 * and gets stuck on downloads ("Content-Disposition: attachment"); Bluefy does nothing on
 * downloads and shows pdfs poorly. There Stocks loads the file itself and shows it in
 * UiFileViewer, whose share button opens the share sheet of iOS (print, save to files, send).
 * Because the file is already loaded, the share sheet opens on the first tap: neither Safari nor
 * Bluefy keep the tap across a fetch. Safari, the pc and Android keep normal links.
 */

export type ViewerFile = {
  name: string;
  // one pdf, or one or more images (e.g. labels for the Niimbot app); shared together
  files: File[];
  loading: boolean;
  error: string | null;
};

// one viewer for the whole app, shown by UiFileViewer (mounted next to the app in app.ts)
const current = ref<ViewerFile | null>(null);
let request: AbortController | null = null;

/**
 * Stocks runs as app from the home screen of an iPhone or iPad. navigator.standalone only exists
 * on iOS and iPadOS, so an installed app on the pc or Android keeps the normal ways.
 */
export const isHomeScreenApp = () =>
  (navigator as Navigator & { standalone?: boolean }).standalone === true;

/**
 * iPhone or iPad; iPads report themselves as Mac, but have a touch screen.
 */
export const isAppleMobile = () =>
  /iPhone|iPad|iPod/.test(navigator.userAgent) ||
  (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

/**
 * Files open in the own viewer: in the home screen app on iOS, and in browsers on iPhone and iPad
 * that have bluetooth (Bluefy and the like; Safari never has it).
 */
export const usesFileViewer = () =>
  isHomeScreenApp() || (isAppleMobile() && "bluetooth" in navigator);

/**
 * File name from the Content-Disposition header, otherwise from the address.
 */
const nameOf = (response: Response, url: URL) => {
  const header = response.headers.get("Content-Disposition") ?? "";
  const encoded = header.match(/filename\*=UTF-8''([^;]+)/i);
  if (encoded) {
    try {
      return decodeURIComponent(encoded[1]);
    } catch {
      // falls through to the plain name
    }
  }
  const plain = header.match(/filename="((?:[^"\\]|\\.)*)"/i);
  if (plain) {
    return plain[1].replace(/\\(.)/g, "$1");
  }
  return decodeURIComponent(url.pathname.split("/").pop() || "Datei");
};

/**
 * A link that opens in the viewer: same origin, and a pdf by its address (".pdf") or marked with
 * data-file-viewer (documents of a loan, whose address has no extension).
 */
export const viewerLink = (target: EventTarget | null) => {
  const link = (target as Element | null)?.closest?.("a[href]");
  if (!(link instanceof HTMLAnchorElement)) {
    return null;
  }
  const url = new URL(link.href, window.location.href);
  if (url.origin !== window.location.origin) {
    return null;
  }
  return link.hasAttribute("data-file-viewer") ||
    url.pathname.toLowerCase().endsWith(".pdf")
    ? url
    : null;
};

export default function useFileViewer() {
  /**
   * Load a file of Stocks and show it.
   */
  const openUrl = async (address: string | URL) => {
    const url = new URL(address, window.location.href);
    request?.abort();
    request = new AbortController();
    current.value = {
      name: decodeURIComponent(url.pathname.split("/").pop() || "Datei"),
      files: [],
      loading: true,
      error: null,
    };
    try {
      const response = await fetch(url, {
        credentials: "same-origin",
        signal: request.signal,
      });
      if (!response.ok) {
        throw new Error(
          response.status === 403 || response.status === 404
            ? "Die Datei ist nicht (mehr) verfügbar."
            : `Die Datei konnte nicht geladen werden (${response.status}).`,
        );
      }
      const blob = await response.blob();
      const name = nameOf(response, url);
      current.value = {
        name,
        files: [new File([blob], name, { type: blob.type })],
        loading: false,
        error: null,
      };
    } catch (error) {
      if (error instanceof DOMException && error.name === "AbortError") {
        return;
      }
      current.value = {
        name: current.value?.name ?? "Datei",
        files: [],
        loading: false,
        error:
          error instanceof Error
            ? error.message
            : "Die Datei konnte nicht geladen werden.",
      };
    }
  };

  /**
   * Show files made in the browser: labels as pdf, or labels as images for the Niimbot app.
   */
  const showFiles = (files: File[], name = files[0]?.name ?? "Datei") => {
    request?.abort();
    current.value = { name, files, loading: false, error: null };
  };
  const showFile = (file: File) => showFiles([file]);

  const close = () => {
    request?.abort();
    current.value = null;
  };

  return { current, openUrl, showFile, showFiles, close };
}
