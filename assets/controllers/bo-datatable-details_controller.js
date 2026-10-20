import { Controller } from '@hotwired/stimulus';

/**
 * Toggles a BoDataTable row's detail line (the columns hidden on narrow viewports).
 * Deliberately not a Bootstrap collapse: collapsing a <tr> breaks its layout, so this
 * controller just flips the native `hidden` attribute and keeps aria-expanded in sync.
 */
export default class extends Controller {
    connect() {
        this.onResize = () => this.#pinGridWidth();
        window.addEventListener('resize', this.onResize);
    }

    disconnect() {
        window.removeEventListener('resize', this.onResize);
    }

    toggle() {
        const detailRow = this.#detailRow();
        if (!detailRow) {
            return;
        }

        const expanded = this.element.getAttribute('aria-expanded') === 'true';

        // Pin the grid's width to the scroll container's visible width
        // *before* revealing the row: the detail <td> spans every column via
        // colspan, so if the browser ever measures the grid unconstrained
        // (even for one layout pass), that min-content inflates the whole
        // table - the horizontal scroll this row exists to avoid.
        if (expanded === false) {
            this.#pinGridWidth(detailRow);
        }

        detailRow.hidden = expanded;
        this.element.setAttribute('aria-expanded', String(!expanded));
    }

    #pinGridWidth(detailRow = this.#detailRow()) {
        const wrapper = this.element.closest('.table-responsive');
        const grid = detailRow ? detailRow.querySelector('.bo-datatable__details-grid') : null;
        if (!wrapper || !grid) {
            return;
        }

        // The grid sits inside the colspan'd <td>: its padding comes on top of the
        // pinned width, and would push the table past the scroll container.
        const cell = grid.closest('td');
        const cellStyle = cell ? getComputedStyle(cell) : null;
        const padding = cellStyle ? parseFloat(cellStyle.paddingLeft) + parseFloat(cellStyle.paddingRight) : 0;

        grid.style.width = `${wrapper.clientWidth - padding}px`;
    }

    #detailRow() {
        const detailId = this.element.getAttribute('aria-controls');
        return detailId ? document.getElementById(detailId) : null;
    }
}
