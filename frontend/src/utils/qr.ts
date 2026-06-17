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

/**
 * QR code with a short label (the boat identifier) drawn in the centre,
 * logo-style. Uses error-correction level H (~30% recoverable) so the
 * centre occlusion doesn't stop it scanning. Returns a PNG data URL.
 */
export async function qrWithCenterLabel(text: string, label: string, size = 320): Promise<string> {
  const canvas = document.createElement('canvas');
  await QRCode.toCanvas(canvas, text, {
    width: size,
    margin: 2,
    errorCorrectionLevel: 'H',
    color: { dark: '#0f172a', light: '#ffffff' },
  });

  const ctx = canvas.getContext('2d');
  if (!ctx || !label) return canvas.toDataURL('image/png');

  // Centre badge: white rounded box with the identifier. Keep it small
  // (~42% wide, ~16% tall) so it stays well within the H-level budget.
  const boxW = Math.round(size * 0.42);
  const boxH = Math.round(size * 0.17);
  const x = Math.round((size - boxW) / 2);
  const y = Math.round((size - boxH) / 2);
  const r = Math.round(boxH * 0.22);

  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + boxW, y, x + boxW, y + boxH, r);
  ctx.arcTo(x + boxW, y + boxH, x, y + boxH, r);
  ctx.arcTo(x, y + boxH, x, y, r);
  ctx.arcTo(x, y, x + boxW, y, r);
  ctx.closePath();
  ctx.fillStyle = '#ffffff';
  ctx.fill();
  ctx.lineWidth = Math.max(2, Math.round(size * 0.01));
  ctx.strokeStyle = '#0f172a';
  ctx.stroke();

  // Fit the identifier text inside the box.
  let fontSize = Math.round(boxH * 0.62);
  const maxTextW = boxW - boxH * 0.4;
  do {
    ctx.font = `700 ${fontSize}px -apple-system, "Segoe UI", Roboto, sans-serif`;
    if (ctx.measureText(label).width <= maxTextW || fontSize <= 8) break;
    fontSize -= 1;
  } while (fontSize > 8);

  ctx.fillStyle = '#0f172a';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(label, size / 2, size / 2 + 1, maxTextW);

  return canvas.toDataURL('image/png');
}
