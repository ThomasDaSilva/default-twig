import { Controller } from '@hotwired/stimulus';

/**
 * Row selection for a listing: drives the "select all" checkbox, keeps a live
 * count, reveals the bulk toolbar and mirrors the selected ids into every bulk
 * form so each submit carries the current selection.
 *
 * Usage:
 *   <div data-controller="bo-bulk-select">
 *       <input type="checkbox" data-bo-bulk-select-target="all" data-action="change->bo-bulk-select#toggleAll">
 *       <input type="checkbox" data-bo-bulk-select-target="row" value="12" data-action="change->bo-bulk-select#onRow">
 *       <div data-bo-bulk-select-target="toolbar" hidden>
 *           <span data-bo-bulk-select-target="count">0</span>
 *           <form data-bo-bulk-select-target="form">…</form>
 *       </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['all', 'row', 'toolbar', 'count', 'form', 'summary'];

    // The name of the hidden inputs carrying the selection, one listing may not be about products.
    static values = { param: { type: String, default: 'product_ids[]' } };

    connect() {
        this.refresh();
    }

    toggleAll(event) {
        const checked = event.currentTarget.checked;
        this.rowTargets.forEach((row) => { row.checked = checked; });
        this.refresh();
    }

    onRow() {
        this.refresh();
    }

    clear() {
        this.rowTargets.forEach((row) => { row.checked = false; });
        if (this.hasAllTarget) {
            this.allTarget.checked = false;
        }
        this.refresh();
    }

    refresh() {
        const selected = this.selectedRows;

        if (this.hasAllTarget) {
            this.allTarget.checked = selected.length > 0 && selected.length === this.rowTargets.length;
            this.allTarget.indeterminate = selected.length > 0 && selected.length < this.rowTargets.length;
        }
        // The count and summary appear both in the toolbar and in the modal.
        this.countTargets.forEach((node) => { node.textContent = String(selected.length); });
        this.toolbarTargets.forEach((node) => { node.hidden = selected.length === 0; });
        const labels = selected.map((row) => row.dataset.label || row.value).join(', ');
        this.summaryTargets.forEach((node) => { node.textContent = labels; });

        this.syncForms(selected.map((row) => row.value));
    }

    /** Rewrites the hidden selection inputs of every bulk form. */
    syncForms(ids) {
        this.formTargets.forEach((form) => {
            form.querySelectorAll('input[data-bulk-selection="1"]').forEach((input) => input.remove());
            ids.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = this.paramValue;
                input.value = id;
                input.dataset.bulkSelection = '1';
                form.appendChild(input);
            });
        });
    }

    get selectedRows() {
        return this.rowTargets.filter((row) => row.checked);
    }
}
