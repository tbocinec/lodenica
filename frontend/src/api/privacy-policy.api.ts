import { http } from './http';

export interface PrivacyPolicy {
  /** HTML content rendered with v-html on the view page. */
  content: string;
  /** ISO-8601 timestamp of the last admin edit, or null if untouched. */
  updatedAt: string | null;
}

export const privacyPolicyApi = {
  async get(): Promise<PrivacyPolicy> {
    const { data } = await http.get<PrivacyPolicy>('/privacy-policy');
    return data;
  },
  async update(content: string): Promise<PrivacyPolicy> {
    const { data } = await http.patch<PrivacyPolicy>('/privacy-policy', { content });
    return data;
  },
};
