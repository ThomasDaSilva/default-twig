import { Controller } from '@hotwired/stimulus';

/**
 * Row selection for a listing: drives the "select all" checkbox, keeps a live
 * count, reveals the bulk toolbar and mirrors the selected ids into every bulk
 * form so each submit carries the current selection.
 *
 * A "restrict" select narrows itself to the options every selected row can take:
 * each row states what it is (data-bulk-state), each option lists the states it
 * accepts (data-bulk-from). The list is a convenience, never the rule: the
 * server decides again on submit.
 *
 * Usage:
 *   <div data-controller="bo-bulk-select">
 *       <input type="checkbox" data-bo-bulk-select-target="all" data-action="change->bo-bulk-select#toggleAll">
 *       <input type="checkbox" data-bo-bulk-select-target="row" value="12" data-bulk-state="3" data-action="change->bo-bulk-select#onRow">
 *       <div data-bo-bulk-select-target="toolbar" hidden>
 *           <span data-bo-bulk-select-target="count">0</span>
 *           <form data-bo-bulk-select-target="form">
 *               <select data-bo-bulk-select-target="restrict">
 *                   <option value="7" data-bulk-from="3,4">Refunded</option>
 *               </select>
 *               <span data-bo-bulk-select-target="restrictHint" hidden>…</span>
 *           </form>
 *       </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['all', 'row', 'toolbar', 'count', 'form', 'summary', 'restrict', 'restrictHint'];

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

        this.restrictTargetOptions(selected);
        this.syncForms(selected.map((row) => row.value));
    }

    /**
     * Hides the options none of the selected rows could take, and says so when
     * nothing is left.
     */
    restrictTargetOptions(selected) {
        if (!this.hasRestrictTarget) {
            return;
        }

        const states = [...new Set(selected.map((row) => row.dataset.bulkState).filter((state) => state))];
        let selectsWithATarget = 0;

        this.restrictTargets.forEach((select) => {
            let available = 0;

            Array.from(select.options).forEach((option) => {
                if (option.value === '') {
                    return;
                }

                const accepted = (option.dataset.bulkFrom || '').split(',').filter(Boolean);
                const reachable = states.length > 0 && states.every((state) => accepted.includes(state));

                option.hidden = !reachable;
                option.disabled = !reachable;
                available += reachable ? 1 : 0;
            });

            selectsWithATarget += available > 0 ? 1 : 0;

            // A target that just went out of reach must not stay picked.
            if (select.selectedOptions.length > 0 && select.selectedOptions[0].disabled) {
                select.value = '';
            }
        });

        // Said only when it is true of the selection as a whole: rows are ticked,
        // and not one of the selects has anything left to offer them.
        this.restrictHintTargets.forEach((node) => { node.hidden = selected.length === 0 || selectsWithATarget > 0; });
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
