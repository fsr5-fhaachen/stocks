/**
 * The device the page runs on, for the few places that differ by system.
 *
 * Android: a file input for photos opens the gallery or the file picker directly, without asking
 * for the camera (iOS asks for camera, photo library or files). Photo inputs offer the camera as
 * its own button there (components/ui/PhotoPicker.vue).
 */
export default function () {
  const agent = typeof navigator === "undefined" ? "" : navigator.userAgent;

  return {
    isAndroid: /Android/i.test(agent),
  };
}
