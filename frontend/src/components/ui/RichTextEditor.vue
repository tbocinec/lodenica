<script setup lang="ts">
/**
 * Minimal TipTap-based WYSIWYG editor with a fixed toolbar (bold,
 * italic, headings H2/H3, bullet + ordered lists, link, undo/redo).
 * Emits the current HTML via v-model. Server-side strips `<script>`
 * and inline event handlers as defence-in-depth.
 */
import { Editor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import { onBeforeUnmount, shallowRef, watch } from 'vue';

const props = defineProps<{
  modelValue: string;
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void;
}>();

// shallowRef so Vue doesn't try to deeply track Editor's internal
// ProseMirror state — TipTap returns its own reactivity through
// onUpdate. Typed as Editor (never null) so EditorContent is happy;
// initialised synchronously below.
const editor = shallowRef<Editor>(
  new Editor({
  content: props.modelValue,
  extensions: [
    StarterKit.configure({
      heading: { levels: [2, 3] },
    }),
    Link.configure({
      openOnClick: false,
      autolink: true,
      HTMLAttributes: {
        rel: 'noopener noreferrer',
        target: '_blank',
      },
    }),
  ],
  editorProps: {
    attributes: {
      class: 'prose prose-slate max-w-none min-h-[240px] focus:outline-none px-3 py-2',
    },
  },
  onUpdate: ({ editor }) => {
    emit('update:modelValue', editor.getHTML());
  },
  }),
);

// External changes (e.g. resetting the editor after a successful save)
// should be reflected in the TipTap instance — but only when they differ
// from what the editor already holds, otherwise we'd ping-pong forever.
watch(
  () => props.modelValue,
  (value) => {
    if (value === editor.value.getHTML()) return;
    editor.value.commands.setContent(value, { emitUpdate: false });
  },
);

onBeforeUnmount(() => {
  editor.value.destroy();
});

function toggleLink(): void {
  const previous = editor.value.getAttributes('link').href as string | undefined;
  const url = window.prompt('Odkaz (URL, prázdne = odstrániť):', previous ?? '');
  if (url === null) return; // cancelled
  if (url === '') {
    editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
    return;
  }
  editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
}

const buttonBase =
  'rounded px-2 py-1 text-sm hover:bg-slate-100 disabled:opacity-40 disabled:hover:bg-transparent';
const activeButton = 'bg-brand-100 text-brand-800';
</script>

<template>
  <div class="overflow-hidden rounded-xl ring-1 ring-slate-200">
    <div
      v-if="editor"
      class="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-slate-50 px-2 py-1.5"
    >
      <button
        type="button"
        :class="[buttonBase, editor.isActive('bold') ? activeButton : '']"
        :disabled="!editor.can().toggleBold()"
        title="Tučné (Ctrl+B)"
        @click="editor.chain().focus().toggleBold().run()"
      >
        <strong>B</strong>
      </button>
      <button
        type="button"
        :class="[buttonBase, editor.isActive('italic') ? activeButton : '']"
        :disabled="!editor.can().toggleItalic()"
        title="Kurzíva (Ctrl+I)"
        @click="editor.chain().focus().toggleItalic().run()"
      >
        <em>I</em>
      </button>
      <span class="mx-1 h-5 w-px bg-slate-300" />
      <button
        type="button"
        :class="[buttonBase, editor.isActive('heading', { level: 2 }) ? activeButton : '']"
        title="Nadpis 2"
        @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
      >
        H2
      </button>
      <button
        type="button"
        :class="[buttonBase, editor.isActive('heading', { level: 3 }) ? activeButton : '']"
        title="Nadpis 3"
        @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"
      >
        H3
      </button>
      <span class="mx-1 h-5 w-px bg-slate-300" />
      <button
        type="button"
        :class="[buttonBase, editor.isActive('bulletList') ? activeButton : '']"
        title="Odrážky"
        @click="editor.chain().focus().toggleBulletList().run()"
      >
        • Zoznam
      </button>
      <button
        type="button"
        :class="[buttonBase, editor.isActive('orderedList') ? activeButton : '']"
        title="Číslovaný zoznam"
        @click="editor.chain().focus().toggleOrderedList().run()"
      >
        1. Zoznam
      </button>
      <span class="mx-1 h-5 w-px bg-slate-300" />
      <button
        type="button"
        :class="[buttonBase, editor.isActive('link') ? activeButton : '']"
        title="Odkaz"
        @click="toggleLink"
      >
        🔗 Odkaz
      </button>
      <span class="ml-auto flex gap-1">
        <button
          type="button"
          :class="buttonBase"
          :disabled="!editor.can().undo()"
          title="Späť (Ctrl+Z)"
          @click="editor.chain().focus().undo().run()"
        >
          ↶
        </button>
        <button
          type="button"
          :class="buttonBase"
          :disabled="!editor.can().redo()"
          title="Dopredu (Ctrl+Shift+Z)"
          @click="editor.chain().focus().redo().run()"
        >
          ↷
        </button>
      </span>
    </div>
    <EditorContent :editor="editor" class="bg-white" />
  </div>
</template>

<style>
/* Tailwind doesn't bundle the typography plugin in this project, so
   inline the small set of prose styles we actually use for the
   reservation-rules page. */
.prose h2 {
  font-size: 1.5rem;
  font-weight: 600;
  margin: 1.25rem 0 0.5rem;
  color: rgb(15 23 42);
}
.prose h3 {
  font-size: 1.125rem;
  font-weight: 600;
  margin: 1rem 0 0.25rem;
  color: rgb(30 41 59);
}
.prose p {
  margin: 0.5rem 0;
  line-height: 1.625;
}
.prose ul {
  margin: 0.5rem 0;
  padding-left: 1.5rem;
  list-style-type: disc;
}
.prose ol {
  margin: 0.5rem 0;
  padding-left: 1.5rem;
  list-style-type: decimal;
}
.prose li {
  margin: 0.125rem 0;
}
.prose a {
  color: rgb(21 91 193);
  text-decoration: underline;
}
.prose strong {
  font-weight: 600;
}
.prose em {
  font-style: italic;
}
.ProseMirror p.is-editor-empty:first-child::before {
  content: attr(data-placeholder);
  float: left;
  color: rgb(148 163 184);
  pointer-events: none;
  height: 0;
}
</style>
