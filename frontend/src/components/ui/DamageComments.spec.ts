import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { DamageComment } from '@/api/types';

import DamageComments from './DamageComments.vue';

const listComments = vi.fn();
const addComment = vi.fn();
const removeComment = vi.fn();

vi.mock('@/api/damages.api', () => ({
  damagesApi: {
    listComments: (...a: unknown[]) => listComments(...a),
    addComment: (...a: unknown[]) => addComment(...a),
    removeComment: (...a: unknown[]) => removeComment(...a),
  },
}));

const comment = (over: Partial<DamageComment> = {}): DamageComment => ({
  id: 'c1',
  damageId: 'd1',
  authorId: 'u1',
  authorName: 'Anna Členka',
  body: 'Doniesol som lepidlo.',
  createdAt: '2026-08-27T09:00:00+00:00',
  ...over,
});

describe('DamageComments', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    listComments.mockReset().mockResolvedValue([comment()]);
    addComment.mockReset();
    removeComment.mockReset();
  });

  it('lists the thread with author and text', async () => {
    const w = mount(DamageComments, { props: { damageId: 'd1', canComment: true } });
    await flushPromises();

    expect(listComments).toHaveBeenCalledWith('d1');
    expect(w.text()).toContain('Anna Členka');
    expect(w.text()).toContain('Doniesol som lepidlo.');
  });

  it('hides the form from people who may not comment', async () => {
    const w = mount(DamageComments, { props: { damageId: 'd1', canComment: false } });
    await flushPromises();

    expect(w.find('textarea').exists()).toBe(false);
    expect(w.text()).toContain('Komentovať môžu prihlásení členovia');
  });

  it('posts a new comment and clears the box', async () => {
    addComment.mockResolvedValue(comment({ id: 'c2', body: 'Hotovo.' }));
    const w = mount(DamageComments, { props: { damageId: 'd1', canComment: true } });
    await flushPromises();

    await w.find('textarea').setValue('Hotovo.');
    await w.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(addComment).toHaveBeenCalledWith('d1', 'Hotovo.');
    expect((w.find('textarea').element as HTMLTextAreaElement).value).toBe('');
  });

  it('refuses to post an empty comment', async () => {
    const w = mount(DamageComments, { props: { damageId: 'd1', canComment: true } });
    await flushPromises();

    await w.find('textarea').setValue('   ');
    await w.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(addComment).not.toHaveBeenCalled();
  });

  it('offers delete only on comments the viewer may remove', async () => {
    listComments.mockResolvedValue([
      comment({ id: 'mine', authorId: 'me' }),
      comment({ id: 'theirs', authorId: 'someone-else' }),
    ]);
    const w = mount(DamageComments, {
      props: { damageId: 'd1', canComment: true, currentUserId: 'me', isAdmin: false },
    });
    await flushPromises();

    expect(w.findAll('[data-test="delete-comment"]')).toHaveLength(1);
  });

  it('lets an admin remove any comment', async () => {
    listComments.mockResolvedValue([
      comment({ id: 'mine', authorId: 'me' }),
      comment({ id: 'theirs', authorId: 'someone-else' }),
    ]);
    const w = mount(DamageComments, {
      props: { damageId: 'd1', canComment: true, currentUserId: 'me', isAdmin: true },
    });
    await flushPromises();

    expect(w.findAll('[data-test="delete-comment"]')).toHaveLength(2);
  });
});
