import { http } from './http';

export type WaterType = 'river' | 'lake' | 'sea' | 'other';

export interface ExpeditionPhoto {
  id: string;
  /** Root-relative API URL, usable directly in <img src>. */
  url: string;
}

export interface Expedition {
  id: string;
  title: string;
  place: string;
  latitude: number;
  longitude: number;
  year: number | null;
  waterType: WaterType | null;
  country: string | null;
  participants: string | null;
  distanceKm: number | null;
  /** Optional route polyline: ordered [lat, lng] pairs. */
  route: [number, number][] | null;
  detail: string | null;
  createdById: string | null;
  createdByName: string | null;
  /** Whether the current viewer may edit/delete this entry (author or admin). */
  canEdit: boolean;
  createdAt: string;
  photos: ExpeditionPhoto[];
}

export interface ExpeditionInput {
  title: string;
  place: string;
  latitude: number;
  longitude: number;
  year?: number | null;
  waterType?: WaterType | null;
  country?: string | null;
  participants?: string | null;
  distanceKm?: number | null;
  detail?: string | null;
  /** Submitter's consent to publish the entry to all members (create only). */
  publishConsent?: boolean;
}

export const expeditionsApi = {
  async list(): Promise<Expedition[]> {
    const { data } = await http.get<Expedition[]>('/expeditions');
    return data;
  },
  async create(input: ExpeditionInput): Promise<Expedition> {
    const { data } = await http.post<Expedition>('/expeditions', input);
    return data;
  },
  async update(id: string, input: Partial<ExpeditionInput>): Promise<Expedition> {
    const { data } = await http.patch<Expedition>(`/expeditions/${id}`, input);
    return data;
  },
  async remove(id: string): Promise<void> {
    await http.delete(`/expeditions/${id}`);
  },
  async uploadPhoto(id: string, file: File): Promise<Expedition> {
    const form = new FormData();
    form.append('photo', file);
    const { data } = await http.post<Expedition>(`/expeditions/${id}/photos`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data;
  },
  async removePhoto(id: string, photoId: string): Promise<void> {
    await http.delete(`/expeditions/${id}/photos/${photoId}`);
  },
};
