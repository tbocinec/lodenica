import { http } from './http';

/**
 * Admin-only data-management API: full DB export/import, CSV exports
 * for resources and reservations, destructive purge of reservations.
 *
 * Auth: Bearer token required (admin role enforced server-side).
 */
export const adminDataApi = {
  /**
   * Returns the full backup JSON as a Blob ready to save to disk.
   * Kept as a blob so the SPA can hand the file to the user via
   * URL.createObjectURL without parsing it.
   */
  async downloadDatabaseJson(): Promise<Blob> {
    const { data } = await http.get<Blob>('/admin/export/database.json', {
      responseType: 'blob',
    });
    return data;
  },
  async downloadReservationsCsv(): Promise<Blob> {
    const { data } = await http.get<Blob>('/admin/export/reservations.csv', {
      responseType: 'blob',
    });
    return data;
  },
  async downloadResourcesCsv(): Promise<Blob> {
    const { data } = await http.get<Blob>('/admin/export/resources.csv', {
      responseType: 'blob',
    });
    return data;
  },

  /**
   * Destructive: wipes all business tables and re-inserts from the
   * payload. `tables` must come from a previous `downloadDatabaseJson`
   * call (and the operator must type "VYMAZAŤ A OBNOVIŤ" to confirm).
   */
  async importDatabase(payload: {
    confirmation: string;
    tables: Record<string, unknown[]>;
  }): Promise<{ ok: boolean; inserted: Record<string, number> }> {
    const { data } = await http.post('/admin/import/database', payload);
    return data;
  },

  async purgeReservations(opts: {
    confirmation: string;
    olderThanDays?: number;
  }): Promise<{ ok: boolean; deleted: number }> {
    const { data } = await http.post('/admin/reservations/purge', opts);
    return data;
  },
};

/**
 * Browser helper — turn a fetched Blob into a download prompt.
 * Used by the AdminDataView buttons so the Authorization header is
 * carried by our axios instance (a plain <a download> can't do that).
 */
export function saveBlobAs(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  // Defer revoke so Safari has time to fire the download.
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
