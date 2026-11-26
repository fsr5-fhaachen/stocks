import { ref } from "vue";

/**
 * Own viewer for pdfs (and images) in the home screen app on iOS and iPadOS.
 *
 * In the home screen app iOS opens a pdf in a frame without share button, ignores window.print()
 * and gets stuck on downloads ("Content-Disposition: attachment"). There Stocks loads the file
 * itself and shows it in UiFileViewer, whose share button opens the share sheet of iOS (print,
 * save to files, send). Because the file is already loaded, the share sheet opens on the first
 * tap: Safari does not keep the tap across a fetch. Safari, the pc and Android keep normal links.
 */

export type ViewerFile = {
  name: string;
  file: File | null;
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
      file: null,
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
        file: new File([blob], name, { type: blob.type }),
        loading: false,
        error: null,
      };
    } catch (error) {
      if (error instanceof DOMException && error.name === "AbortError") {
        return;
      }
      current.value = {
        name: current.value?.name ?? "Datei",
        file: null,
        loading: false,
        error:
          error instanceof Error
            ? error.message
            : "Die Datei konnte nicht geladen werden.",
      };
    }
  };

  /**
   * Show a file made in the browser (e.g. labels as pdf).
   */
  const showFile = (file: File) => {
    request?.abort();
    current.value = { name: file.name, file, loading: false, error: null };
  };

  const close = () => {
    request?.abort();
    current.value = null;
  };

  return { current, openUrl, showFile, close };
}
