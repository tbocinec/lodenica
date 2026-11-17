import { RouterLinkStub, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import { ResourceType, type Resource } from '@/api/types';

import ResourceDetailDialog from './ResourceDetailDialog.vue';

const resource = (over: Partial<Resource> = {}): Resource => ({
  id: 'r1',
  identifier: 'K-001',
  type: ResourceType.SEA_KAYAK,
  name: 'Valley',
  displayName: 'Valley Aquanaut HV',
  model: 'Aquanaut HV',
  color: 'fialová',
  seats: 1,
  lengthCm: 520,
  weightKg: 24,
  note: 'Pozor na kormidlo.\nSkladá sa ťažko.',
  photoUrl: '/api/v1/resources/r1/photo?v=1',
  isActive: true,
  requiresApproval: false,
  approvers: null,
  openDamage: null,
  openDamageCount: 0,
  createdAt: '2026-01-01T00:00:00+00:00',
  updatedAt: '2026-01-01T00:00:00+00:00',
  ...over,
});

const mountDialog = (r: Resource) =>
  mount(ResourceDetailDialog, {
    props: { resource: r },
    global: { stubs: { RouterLink: RouterLinkStub } },
    attachTo: document.body,
  });

describe('ResourceDetailDialog', () => {
  it('shows parameters and the note', () => {
    const w = mountDialog(resource());
    const body = document.body.textContent ?? '';
    expect(body).toContain('K-001');
    expect(body).toContain('Aquanaut HV');
    expect(body).toContain('5.20 m');
    expect(document.querySelector('[data-testid="resource-note"]')?.textContent).toContain(
      'Pozor na kormidlo.',
    );
    w.unmount();
  });

  it('enlarges the photo on click and closes the lightbox again', async () => {
    const w = mountDialog(resource());
    expect(document.querySelector('[data-testid="image-lightbox"]')).toBeNull();

    (document.querySelector('button[title="Zväčšiť fotku"]') as HTMLElement).click();
    await w.vm.$nextTick();
    expect(document.querySelector('[data-testid="image-lightbox"]')).not.toBeNull();

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await w.vm.$nextTick();
    expect(document.querySelector('[data-testid="image-lightbox"]')).toBeNull();
    // Escape closed only the lightbox, not the dialog under it.
    expect(w.emitted('close')).toBeUndefined();
    w.unmount();
  });

  it('links to the separate resource page in a new tab', () => {
    const w = mountDialog(resource());
    const link = w.findComponent(RouterLinkStub);
    expect(link.props().to).toBe('/resources/r1');
    expect(link.attributes('target')).toBe('_blank');
    w.unmount();
  });

  it('works for a boat without photo and note', () => {
    const w = mountDialog(
      resource({ photoUrl: null, note: null, model: null, color: null, seats: null, lengthCm: null, weightKg: null }),
    );
    expect(document.querySelector('button[title="Zväčšiť fotku"]')).toBeNull();
    expect(document.body.textContent).toContain('žiadne ďalšie údaje');
    w.unmount();
  });

  it('shows manufacturer + model in the title', () => {
    const w = mountDialog(resource());
    expect(document.body.textContent).toContain('K-001 · Valley Aquanaut HV');
    w.unmount();
  });

  it('shows only the parameters of a trailer (name, seats, weight, note)', () => {
    const w = mountDialog(
      resource({
        type: ResourceType.TRAILER,
        name: 'Príves malý modrý',
        displayName: 'Príves malý modrý',
        model: 'Klarex s.r.o.',
        color: 'Modrá',
        seats: 12,
        lengthCm: null,
        weightKg: 300,
        photoUrl: null,
      }),
    );
    const body = document.body.textContent ?? '';
    expect(body).toContain('Počet miest');
    expect(body).toContain('300 kg');
    expect(body).not.toContain('Klarex');
    expect(body).not.toContain('Farba');
    w.unmount();
  });

  it('shows just the note for a boathouse space', () => {
    const w = mountDialog(
      resource({
        type: ResourceType.BOATHOUSE_SPACE,
        name: 'Nová lodenica',
        displayName: 'Nová lodenica',
        model: null,
        color: null,
        seats: null,
        lengthCm: null,
        weightKg: null,
        photoUrl: null,
        note: 'Vstup zozadu.',
      }),
    );
    expect(document.querySelector('[data-testid="resource-note"]')?.textContent).toBe('Vstup zozadu.');
    expect(document.body.textContent).not.toContain('Model');
    w.unmount();
  });

  it('emits close from the ✕ button and on Escape', async () => {
    const w = mountDialog(resource());
    (document.querySelector('button[aria-label="Zavrieť"]') as HTMLElement).click();
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(w.emitted('close')?.length).toBe(2);
    w.unmount();
  });
});
