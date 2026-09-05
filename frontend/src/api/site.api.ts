import { http } from './http';

/**
 * Site identity of this installation — the club's name, contacts, links,
 * module switches, default theme and logo. Public `GET /site` feeds the
 * shell before login; the admin endpoints also carry `adminEmail`.
 * Mirrors App\Services\SiteConfig on the backend.
 */

export interface SiteFeatures {
  /** Danube paddling traffic light (Bratislava clubs only). */
  paddlingTrafficLight: boolean;
  expeditions: boolean;
}

export interface SiteConfig {
  /** Full name — e-mails, ICS, browser title. */
  siteName: string;
  /** Header + login heading. */
  shortName: string;
  /** Legal/full club name for content pages and consents. */
  clubName: string;
  contactEmail: string | null;
  /** Boathouse address for calendar exports. */
  address: string;
  mapsUrl: string | null;
  websiteUrl: string | null;
  /** Prevádzkový poriadok. */
  rulesUrl: string | null;
  gdprNoticeUrl: string | null;
  gdprConsentUrl: string | null;
  statutesUrl: string | null;
  /** "Prevádzkovateľ: …" sentence shown in the registration consent block. */
  operatorNotice: string;
  memberIdExample: string;
  features: SiteFeatures;
  /** Default colour theme key (src/theme/themes.ts). */
  theme: string;
  /** Absolute URL of the uploaded logo, or null for the bundled default. */
  logoUrl: string | null;
}

export interface AdminSiteConfig extends SiteConfig {
  /** Where "new member waiting" notices go; null = contactEmail. */
  adminEmail: string | null;
}

/** Partial update — only sent keys change; null puts a field back to its default. */
export type SiteConfigPatch = Partial<Omit<AdminSiteConfig, 'logoUrl' | 'features'>> & {
  features?: Partial<SiteFeatures>;
};

/** What the SPA assumes until `GET /site` answers (or when it never does). */
export const DEFAULT_SITE_CONFIG: SiteConfig = {
  siteName: 'Lodenica',
  shortName: 'Lodenica',
  clubName: '',
  contactEmail: null,
  address: '',
  mapsUrl: null,
  websiteUrl: null,
  rulesUrl: null,
  gdprNoticeUrl: null,
  gdprConsentUrl: null,
  statutesUrl: null,
  operatorNotice: '',
  memberIdExample: '001',
  features: { paddlingTrafficLight: false, expeditions: true },
  theme: 'ocean',
  logoUrl: null,
};

export const siteApi = {
  async get(): Promise<SiteConfig> {
    const { data } = await http.get<SiteConfig>('/site');
    return data;
  },
  async adminGet(): Promise<AdminSiteConfig> {
    const { data } = await http.get<AdminSiteConfig>('/admin/site');
    return data;
  },
  async update(patch: SiteConfigPatch): Promise<AdminSiteConfig> {
    const { data } = await http.patch<AdminSiteConfig>('/admin/site', patch);
    return data;
  },
  async uploadLogo(file: File): Promise<AdminSiteConfig> {
    const form = new FormData();
    form.append('logo', file);
    const { data } = await http.post<AdminSiteConfig>('/admin/site/logo', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data;
  },
  async removeLogo(): Promise<void> {
    await http.delete('/admin/site/logo');
  },
};
