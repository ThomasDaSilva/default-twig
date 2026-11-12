import { Controller } from '@hotwired/stimulus';
import { tokenBody } from '../lib/post-request.js';

/**
 * Native HTML5 drag-and-drop reorder for tables whose <tbody> hosts
 * the controller. Each <tr> must carry a `data-row-id="<id>"` attribute.
 * A table with one <tbody> per item sets `item` to `tbody[data-row-id]` and hosts the controller on
 * the <table>. The drag image is always the whole item, even when a handle starts the drag.
 * On drop, POSTs the moved row's id and its new 1-based position to the
 * configured URL (the token in the request body), then reloads the page so the server-rendered table
 * reflects the persisted order.
 *
 * With `json` set, the URL answers in JSON and the page is not reloaded, so typed fields are kept;
 * the `reindexedFields` (read by row index, `onsale[2]`) take the index of their new place. A failed
 * move reveals `failureElement` and stops the moves after it, whose positions would be wrong.
 * `disabled` freezes dragging while the handles serve another use (a selection box).
 */
export default class extends Controller {
    static values = {
        url: String,
        token: String,
        paramName: { type: String, default: 'id' },
        item: { type: String, default: 'tr[data-row-id]' },
        json: { type: Boolean, default: false },
        failureElement: String,
        reindexedFields: { type: Array, default: [] },
        disabled: { type: Boolean, default: false },
    };

    connect() {
        this.dragged = null;
        this.broken = false;
        this.pending = Promise.resolve();
        this.rows().forEach((row) => this.makeDraggable(row));
        this.boundDragStart = this.onDragStart.bind(this);
        this.boundDragOver = this.onDragOver.bind(this);
        this.boundDragLeave = this.onDragLeave.bind(this);
        this.boundDrop = this.onDrop.bind(this);
        this.boundDragEnd = this.onDragEnd.bind(this);
        this.element.addEventListener('dragstart', this.boundDragStart);
        this.element.addEventListener('dragover', this.boundDragOver);
        this.element.addEventListener('dragleave', this.boundDragLeave);
        this.element.addEventListener('drop', this.boundDrop);
        this.element.addEventListener('dragend', this.boundDragEnd);
    }

    disconnect() {
        this.element.removeEventListener('dragstart', this.boundDragStart);
        this.element.removeEventListener('dragover', this.boundDragOver);
        this.element.removeEventListener('dragleave', this.boundDragLeave);
        this.element.removeEventListener('drop', this.boundDrop);
        this.element.removeEventListener('dragend', this.boundDragEnd);
    }

    rows() {
        return Array.from(this.element.querySelectorAll(this.itemValue));
    }

    makeDraggable(row) {
        // When a row exposes an explicit handle, only the handle starts the
        // drag so inline form fields stay editable. Otherwise the whole row
        // is draggable (default behaviour for handle-less lists).
        const handle = row.querySelector('[data-bo-sortable-handle]');
        const draggable = String(!this.disabledValue);
        if (handle) {
            handle.setAttribute('draggable', draggable);
            handle.classList.toggle('bo-sortable-handle', !this.disabledValue);
        } else {
            row.setAttribute('draggable', draggable);
        }
        row.classList.toggle('bo-sortable-row', !this.disabledValue);
    }

    disabledValueChanged() {
        this.rows().forEach((row) => this.makeDraggable(row));
    }

    onDragStart(event) {
        const row = event.target.closest(this.itemValue);
        if (!row || row.parentElement !== this.element || this.disabledValue) {
            return;
        }
        // Only the handle starts a move (the item itself when it has none): an image or a link of a cell
        // is draggable on its own, and dropping it on another row would reorder the list.
        const handle = row.querySelector('[data-bo-sortable-handle]');
        if (event.target !== (handle ?? row)) {
            return;
        }
        this.dragged = row;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.rowId || '');

        // The browser snapshots the item once this handler returns: dim it after, or the snapshot is dimmed too.
        const box = row.getBoundingClientRect();
        event.dataTransfer.setDragImage(row, event.clientX - box.left, event.clientY - box.top);
        window.requestAnimationFrame(() => {
            if (this.dragged === row) {
                row.classList.add('bo-sortable-dragging');
            }
        });
    }

    onDragOver(event) {
        const row = event.target.closest(this.itemValue);
        // No drop once a move failed: the browser would handle it itself.
        if (!row || !this.dragged || row === this.dragged || this.broken) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        const rows = this.rows();
        row.classList.add('bo-sortable-target');
        row.classList.toggle('bo-sortable-target--after', rows.indexOf(this.dragged) < rows.indexOf(row));
    }

    onDragLeave(event) {
        const row = event.target.closest(this.itemValue);
        // Moving from one cell to the next stays on the row: the mark would blink off and on.
        if (row && !row.contains(event.relatedTarget)) {
            row.classList.remove('bo-sortable-target', 'bo-sortable-target--after');
        }
    }

    onDrop(event) {
        const target = event.target.closest(this.itemValue);
        if (!target || !this.dragged || target === this.dragged || this.broken) {
            return;
        }
        event.preventDefault();
        target.classList.remove('bo-sortable-target', 'bo-sortable-target--after');

        // A row showing its position is sent to that very position: the list may be
        // one page of many, or carry gaps, and its rank on screen would then be wrong.
        const targetPosition = Number.parseInt(target.querySelector('.bo-position')?.dataset.position ?? '', 10);

        const rows = this.rows();
        const fromIndex = rows.indexOf(this.dragged);
        const toIndex = rows.indexOf(target);
        if (fromIndex < 0 || toIndex < 0) {
            return;
        }

        if (fromIndex < toIndex) {
            target.after(this.dragged);
        } else {
            target.before(this.dragged);
        }

        const newPosition = Number.isInteger(targetPosition) ? targetPosition : this.rows().indexOf(this.dragged) + 1;
        const rowId = this.dragged.dataset.rowId;
        if (!this.jsonValue) {
            this.persist(rowId, newPosition);

            return;
        }

        this.reindexRows();
        this.pending = this.pending.then(() => this.persistAsJson(rowId, newPosition));
    }

    onDragEnd() {
        if (this.dragged) {
            this.dragged.classList.remove('bo-sortable-dragging');
            this.dragged = null;
        }
        this.element
            .querySelectorAll('.bo-sortable-target')
            .forEach((row) => row.classList.remove('bo-sortable-target', 'bo-sortable-target--after'));
    }

    request(rowId, position, accept) {
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set(this.paramNameValue, rowId);
        url.searchParams.set('position', String(position));

        // The token travels in the body, never in the URL.
        const body = tokenBody();
        if (this.tokenValue) {
            body.set('_token', this.tokenValue);
        }

        return fetch(url.toString(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: accept, 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
    }

    persist(rowId, position) {
        this.request(rowId, position, 'text/html')
            .then((response) => {
                if (!response.ok && response.status >= 400) {
                    throw new Error(`HTTP ${response.status}`);
                }
                window.location.reload();
            })
            .catch(() => {
                window.location.reload();
            });
    }

    async persistAsJson(rowId, position) {
        if (this.broken) {
            return;
        }

        try {
            const response = await this.request(rowId, position, 'application/json');
            // An expired session redirects to the login page, which does not parse as JSON.
            await response.json();
            if (!response.ok) {
                this.failed();
            }
        } catch {
            this.failed();
        }
    }

    failed() {
        this.broken = true;
        const failure = this.hasFailureElementValue ? document.querySelector(this.failureElementValue) : null;
        if (failure) {
            failure.hidden = false;
        }
    }

    reindexRows() {
        this.rows().forEach((row, index) => {
            this.reindexedFieldsValue.forEach((name) => {
                row.querySelectorAll(`[name^="${name}["]`).forEach((field) => {
                    field.name = field.name.replace(/\[\d+\]$/, `[${index}]`);
                });
            });
        });
    }
}
