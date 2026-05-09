import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import StatCard from './StatCard.vue';

describe('StatCard', () => {
  it('renders label and value', () => {
    const wrapper = mount(StatCard, {
      props: { label: 'Aktívnych zdrojov', value: 12 },
    });
    expect(wrapper.text()).toContain('Aktívnych zdrojov');
    expect(wrapper.text()).toContain('12');
  });

  it('applies amber tone when set', () => {
    const wrapper = mount(StatCard, {
      props: { label: 'Dnes obsadené', value: 3, tone: 'amber' },
    });
    expect(wrapper.html()).toContain('text-amber-700');
  });
});
