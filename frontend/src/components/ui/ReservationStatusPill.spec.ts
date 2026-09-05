import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import ReservationStatusPill from './ReservationStatusPill.vue';

describe('ReservationStatusPill', () => {
  it('names a waiting request and colours it amber', () => {
    const w = mount(ReservationStatusPill, { props: { status: 'PENDING_APPROVAL' } });
    expect(w.text()).toContain('Čaká na schválenie');
    expect(w.html()).toContain('pill-amber');
  });

  it('names a rejection and colours it red', () => {
    const w = mount(ReservationStatusPill, { props: { status: 'REJECTED' } });
    expect(w.text()).toContain('Zamietnutá');
    expect(w.html()).toContain('pill-red');
  });

  it('stays quiet for a confirmed booking unless asked', () => {
    expect(mount(ReservationStatusPill, { props: { status: 'CONFIRMED' } }).text()).toBe('');
    const shown = mount(ReservationStatusPill, { props: { status: 'CONFIRMED', showConfirmed: true } });
    expect(shown.text()).toContain('Potvrdená');
    expect(shown.html()).toContain('pill-green');
  });

  it('marks a cancellation in slate', () => {
    expect(mount(ReservationStatusPill, { props: { status: 'CANCELLED' } }).html()).toContain('pill-slate');
  });
});
