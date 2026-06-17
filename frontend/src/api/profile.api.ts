import { http } from './http';
import type { UserIdentity } from './types';

/**
 * The signed-in user's own-account operations. Changing OTHER users'
 * passwords is admin-only and lives in users.api (PATCH /users/{id}).
 */
export const profileApi = {
  async changePassword(currentPassword: string, newPassword: string): Promise<void> {
    await http.post('/profile/change-password', { currentPassword, newPassword });
  },
  async identities(): Promise<UserIdentity[]> {
    const { data } = await http.get<UserIdentity[]>('/profile/identities');
    return data;
  },
  async unlinkIdentity(provider: string): Promise<void> {
    await http.delete(`/profile/identities/${provider}`);
  },
  /** Get the OAuth redirect URL (with a signed state) to LINK a provider. */
  async linkUrl(provider: string): Promise<string> {
    const { data } = await http.get<{ url: string }>(`/profile/oauth/${provider}/link-url`);
    return data.url;
  },
};
