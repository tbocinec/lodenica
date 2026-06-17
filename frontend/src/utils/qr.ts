import QRCode from 'qrcode';

/**
 * Booking URL a QR code points to: opens the reservation form pre-filled
 * with this resource and a "quick 3-hour from now" slot (the form reads
 * `quick=3h`). Absolute URL so a printed/scanned code works from any device.
 */
export function resourceBookingUrl(resourceId: string): string {
  const origin =
    typeof window !== 'undefined' ? window.location.origin : 'https://rezervacie.lodenicakvs.sk';
  return `${origin}/reservations/new?resourceId=${encodeURIComponent(resourceId)}&quick=3h`;
}

/** Render a QR code for the given text as a PNG data URL (for <img> + download). */
export function qrDataUrl(text: string, size = 320): Promise<string> {
  return QRCode.toDataURL(text, {
    width: size,
    margin: 2,
    errorCorrectionLevel: 'M',
    color: { dark: '#0f172a', light: '#ffffff' },
  });
}
