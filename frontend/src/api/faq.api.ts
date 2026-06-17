import { http } from './http';

export interface Faq {
  /** HTML content rendered with v-html on the view page. */
  content: string;
  /** ISO-8601 timestamp of the last admin edit, or null if untouched. */
  updatedAt: string | null;
}

export const faqApi = {
  async get(): Promise<Faq> {
    const { data } = await http.get<Faq>('/faq');
    return data;
  },
  async update(content: string): Promise<Faq> {
    const { data } = await http.patch<Faq>('/faq', { content });
    return data;
  },
};
