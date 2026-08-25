import { http } from './http';

/**
 * Admin-only mail diagnostics + the per-notification on/off switches.
 *
 * Auth: Bearer token required (admin role enforced server-side).
 */

export interface MailConfig {
  mailer: string;
  mailerDefined: boolean;
  transport: string | null;
  scheme: string | null;
  host: string | null;
  port: number | null;
  username: string | null;
  /** Whether an SMTP password is configured. The value is never sent. */
  passwordSet: boolean;
  fromAddress: string | null;
  fromName: string | null;
  adminAddress: string | null;
  appUrl: string | null;
  queueConnection: string | null;
  /** Blade's compile directory — null when it resolved to nothing usable. */
  viewCompiledPath: string | null;
  viewCompiledWritable: boolean;
}

export interface MailTestResult {
  ok: boolean;
  to: string;
  durationMs: number;
  sentAt: string;
  errorClass: string | null;
  errorMessage: string | null;
}

export interface MailLogTail {
  available: boolean;
  reason: string | null;
  path: string;
  entries: string[];
}

export interface MailNotificationToggle {
  key: string;
  label: string;
  description: string;
  /** A member cannot reach their account without this e-mail. */
  critical: boolean;
  consequence: string;
  enabled: boolean;
}

export const mailDiagnosticsApi = {
  async getConfig(): Promise<MailConfig> {
    const { data } = await http.get<MailConfig>('/admin/mail/config');
    return data;
  },

  /**
   * Longer timeout than the shared default: a broken SMTP host can sit in
   * a connect timeout for far longer than 15s, and cutting the request off
   * client-side would hide the very error we're trying to read.
   */
  async sendTest(to: string): Promise<MailTestResult> {
    const { data } = await http.post<MailTestResult>(
      '/admin/mail/test',
      { to },
      { timeout: 120_000 },
    );
    return data;
  },

  async getLog(lines = 200): Promise<MailLogTail> {
    const { data } = await http.get<MailLogTail>('/admin/mail/log', { params: { lines } });
    return data;
  },

  async getNotifications(): Promise<MailNotificationToggle[]> {
    const { data } = await http.get<{ notifications: MailNotificationToggle[] }>(
      '/admin/mail/notifications',
    );
    return data.notifications;
  },

  /** Partial update — only the keys sent change. */
  async setNotifications(changes: Record<string, boolean>): Promise<MailNotificationToggle[]> {
    const { data } = await http.patch<{ notifications: MailNotificationToggle[] }>(
      '/admin/mail/notifications',
      changes,
    );
    return data.notifications;
  },
};
