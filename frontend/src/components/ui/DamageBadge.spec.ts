import { RouterLinkStub, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import type { OpenDamage } from '@/api/types';

import DamageBadge from './DamageBadge.vue';

const damage = (over: Partial<OpenDamage> = {}): OpenDamage => ({
  id: 'dmg-1',
  status: 'REPORTED',
  severity: 'MODERATE',
  description: 'prasklina v dne',
  reportedAt: '2026-08-01T10:00:00+00:00',
  ...over,
});

const mountBadge = (props: { damage: OpenDamage; detailed?: boolean; linkToDetail?: boolean }) =>
  mount(DamageBadge, {
    props,
    global: { stubs: { RouterLink: RouterLinkStub } },
  });

describe('DamageBadge', () => {
  it('names the severity and the status', () => {
    const wrapper = mountBadge({ damage: damage() });
    expect(wrapper.text()).toContain('Poškodená');
    expect(wrapper.text()).toContain('Stredné');
  });

  it('colours by severity, reusing the damages module scale', () => {
    expect(mountBadge({ damage: damage({ severity: 'MINOR' }) }).html()).toContain('pill-slate');
    expect(mountBadge({ damage: damage({ severity: 'MODERATE' }) }).html()).toContain('pill-amber');
    expect(mountBadge({ damage: damage({ severity: 'CRITICAL' }) }).html()).toContain('pill-red');
  });

  it('links to the damage detail when asked', () => {
    const wrapper = mountBadge({ damage: damage(), linkToDetail: true });
    expect(wrapper.findComponent(RouterLinkStub).props().to).toBe('/damages/dmg-1');
  });

  it('stays inert when no link is wanted', () => {
    const wrapper = mountBadge({ damage: damage() });
    expect(wrapper.findComponent(RouterLinkStub).exists()).toBe(false);
  });

  it('shows the description only in the detailed variant', () => {
    expect(mountBadge({ damage: damage() }).text()).not.toContain('prasklina v dne');
    expect(mountBadge({ damage: damage(), detailed: true }).text()).toContain('prasklina v dne');
  });
});
