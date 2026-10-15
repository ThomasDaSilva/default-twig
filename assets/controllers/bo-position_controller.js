import { Controller } from '@hotwired/stimulus';
import { tokenBody } from '../lib/post-request.js';

/**
 * Position cell of a manually ordered table, hosted by its <tbody>: the arrows
 * move a row one step, and the number opens a small field to send it to any
 * position — the only way to move a row to another page of a paginated list.
 *
 * Every move goes through the list's update-position endpoint (mode 1 up, 2 down,
 * 3 absolute), then the page reloads so the server-rendered order is the truth.
 */
const MODE_UP = 1;
const MODE_DOWN = 2;
const MODE_ABSOLUTE = 3;

export default class extends Controller {
    static values = {
        url: String,
        token: String,
        paramName: { type: String, default: 'id' },
        title: { type: String, default: 'Position' },
        saveLabel: { type: String, default: 'Save' },
        cancelLabel: { type: String, default: 'Cancel' },
    };

    connect() {
        this.editor = null;
        this.onOutsideClick = (event) => {
            if (this.editor && !this.editor.contains(event.target) && !event.target.closest('.bo-position__value')) {
                this.closeEditor();
            }
        };
        this.onViewportChange = () => this.closeEditor();
    }

    disconnect() {
        this.closeEditor();
    }

    up(event) {
        this.move(event.currentTarget, MODE_UP);
    }

    down(event) {
        this.move(event.currentTarget, MODE_DOWN);
    }

    edit(event) {
        const trigger = event.currentTarget;
        if (this.editor && this.editor.trigger === trigger) {
            this.closeEditor();

            return;
        }

        this.closeEditor();
        this.openEditor(trigger);
    }

    // ---------- editor ----------

    openEditor(trigger) {
        const current = trigger.closest('.bo-position')?.dataset.position ?? '';

        const editor = document.createElement('div');
        editor.className = 'popover bo-position__editor';
        editor.setAttribute('role', 'dialog');
        editor.setAttribute('aria-label', this.titleValue);
        editor.dataset.testid = 'bo-position-editor';

        const header = document.createElement('div');
        header.className = 'popover-header';
        header.textContent = this.titleValue;

        const form = document.createElement('form');
        form.className = 'popover-body d-flex gap-2';

        const input = document.createElement('input');
        input.type = 'number';
        input.min = '1';
        input.step = '1';
        input.required = true;
        input.value = current;
        input.className = 'form-control form-control-sm';
        input.setAttribute('aria-label', this.titleValue);

        const save = this.button('btn-primary', 'bi-check-lg', this.saveLabelValue, 'submit');
        const cancel = this.button('btn-outline-secondary', 'bi-x-lg', this.cancelLabelValue, 'button');
        cancel.addEventListener('click', () => this.closeEditor(true));

        form.append(input, save, cancel);
        form.addEventListener('submit', (submitEvent) => {
            submitEvent.preventDefault();
            const position = Number.parseInt(input.value, 10);
            if (!Number.isInteger(position) || position < 1) {
                input.classList.add('is-invalid');

                return;
            }
            if (String(position) === current) {
                this.closeEditor(true);

                return;
            }
            this.move(trigger, MODE_ABSOLUTE, position);
        });
        input.addEventListener('keydown', (keyEvent) => {
            if (keyEvent.key === 'Escape') {
                keyEvent.preventDefault();
                this.closeEditor(true);
            }
        });

        editor.append(header, form);
        editor.trigger = trigger;
        // Appended to the body rather than the cell: the table sits in a scrolling
        // wrapper that would clip a popover anchored inside it.
        document.body.append(editor);
        this.place(editor, trigger);

        this.editor = editor;
        trigger.setAttribute('aria-expanded', 'true');
        document.addEventListener('mousedown', this.onOutsideClick);
        window.addEventListener('resize', this.onViewportChange);

        input.focus();
        input.select();
    }

    button(variant, icon, label, type) {
        const button = document.createElement('button');
        button.type = type;
        button.className = `btn btn-sm ${variant}`;
        button.setAttribute('aria-label', label);
        button.title = label;
        const glyph = document.createElement('i');
        glyph.className = `bi ${icon}`;
        glyph.setAttribute('aria-hidden', 'true');
        button.append(glyph);

        return button;
    }

    place(editor, trigger) {
        const anchor = trigger.getBoundingClientRect();
        const width = editor.offsetWidth;
        const left = Math.max(8, Math.min(
            anchor.left + anchor.width / 2 - width / 2,
            document.documentElement.clientWidth - width - 8,
        ));
        editor.style.top = `${anchor.bottom + window.scrollY + 6}px`;
        editor.style.left = `${left + window.scrollX}px`;
    }

    closeEditor(restoreFocus = false) {
        if (!this.editor) {
            return;
        }
        const { trigger } = this.editor;
        this.editor.remove();
        this.editor = null;
        trigger.setAttribute('aria-expanded', 'false');
        document.removeEventListener('mousedown', this.onOutsideClick);
        window.removeEventListener('resize', this.onViewportChange);
        if (restoreFocus) {
            trigger.focus();
        }
    }

    // ---------- persistence ----------

    move(trigger, mode, position = 0) {
        const rowId = this.rowId(trigger);
        if (!rowId) {
            return;
        }

        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set(this.paramNameValue, rowId);
        url.searchParams.set('mode', String(mode));
        url.searchParams.set('position', String(position));

        // The token travels in the body, never in the URL.
        const body = tokenBody();
        if (this.tokenValue) {
            body.set('_token', this.tokenValue);
        }

        this.element.setAttribute('aria-busy', 'true');
        fetch(url.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'text/html', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        }).finally(() => {
            window.location.reload();
        });
    }

    /**
     * A row hidden columns are folded into carries no id: its details row follows
     * the main one, which does.
     */
    rowId(trigger) {
        const row = trigger.closest('tr');
        if (!row) {
            return '';
        }

        return row.dataset.rowId || row.previousElementSibling?.dataset.rowId || '';
    }
}
