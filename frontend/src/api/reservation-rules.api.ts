import { http } from './http';

export interface ReservationRules {
  /** HTML content rendered with v-html on the view page. */
  content: string;
  /** ISO-8601 timestamp of the last admin edit, or null if untouched. */
  updatedAt: string | null;
}

export const reservationRulesApi = {
  async get(): Promise<ReservationRules> {
    const { data } = await http.get<ReservationRules>('/reservation-rules');
    return data;
  },
  async update(content: string): Promise<ReservationRules> {
    const { data } = await http.patch<ReservationRules>('/reservation-rules', { content });
    return data;
  },
};
