import { Controller } from '@hotwired/stimulus';

/**
 * The edit mode of the lines of an order.
 *
 * The button swaps the read-only table for the edit form. The changes stay in the form (nothing is
 * written until it is submitted); each one asks the server what the totals would become, because
 * the tax is not computed in the browser. The preview is requested when a field loses focus
 * (`change`), not on every keystroke. Abandoning resets the form and brings the table back.
 *
 * The lines are paginated in the browser: every row stays in the form, so one POST carries the
 * whole draft whatever the page shown.
 */
export default class extends Controller {
    static targets = [
        'toolbar', 'start', 'view', 'form', 'heading', 'error', 'submit',
        'line', 'pager', 'previous', 'next', 'pageLabel',
        'search', 'searchStatus', 'results', 'resultTemplate',
        'added', 'addedWrapper', 'addedTemplate',
        'before', 'after',
    ];

    static values = {
        previewUrl: String,
        searchUrl: String,
        currency: String,
        locale: String,
        perPage: { type: Number, default: 25 },
        pageLabel: String,
    };

    connect() {
        this.page = 1;
        this.addedCount = 0;
        this.previewSequence = 0;
        this.previewRequest = null;
        this.searchSequence = 0;
        this.searchRequest = null;
        this.searchTimer = null;
    }

    disconnect() {
        this.previewRequest?.abort();
        this.searchRequest?.abort();
        clearTimeout(this.searchTimer);
    }

    start() {
        this.viewTarget.hidden = true;
        this.toolbarTarget.hidden = true;
        this.formTarget.hidden = false;
        this.startTarget.setAttribute('aria-expanded', 'true');
        this.showPage(1);
        this.headingTarget.focus();
    }

    abandon() {
        this.previewSequence++;
        this.searchSequence++;
        this.previewRequest?.abort();
        this.searchRequest?.abort();
        clearTimeout(this.searchTimer);

        this.formTarget.reset();
        this.addedTarget.replaceChildren();
        this.addedWrapperTarget.hidden = true;
        this.clearResults();
        this.clearTotals();
        this.showError('');
        this.updateSubmit(false);

        this.formTarget.hidden = true;
        this.viewTarget.hidden = false;
        this.toolbarTarget.hidden = false;
        this.startTarget.setAttribute('aria-expanded', 'false');
        this.startTarget.focus();
    }

    // Enter in a field validates the field, it must not save the whole draft.
    keydown(event) {
        if (event.key !== 'Enter' || event.target.tagName !== 'INPUT' || event.target.type === 'checkbox') {
            return;
        }

        event.preventDefault();

        if (event.target === this.searchTarget) {
            clearTimeout(this.searchTimer);
            this.search();

            return;
        }

        this.changed(event);
    }

    submit(event) {
        if (!this.hasChanges()) {
            event.preventDefault();
        }
    }

    // Only the fields that are sent count: the search box has no name.
    changed(event) {
        if (event?.target && !event.target.name && !event.target.hasAttribute?.('data-field')) {
            return;
        }

        this.preview();
    }

    hasChanges() {
        if (this.addedTarget.children.length > 0) {
            return true;
        }

        return Array.from(this.formTarget.querySelectorAll('input[name]')).some((input) => {
            if (input.type === 'hidden') {
                return false;
            }

            return input.type === 'checkbox' ? input.checked !== input.defaultChecked : input.value !== input.defaultValue;
        });
    }

    async preview() {
        this.previewRequest?.abort();
        const sequence = ++this.previewSequence;

        if (!this.hasChanges()) {
            this.clearTotals();
            this.showError('');
            this.updateSubmit(false);

            return;
        }

        const controller = new AbortController();
        this.previewRequest = controller;

        try {
            const response = await fetch(this.previewUrlValue, {
                method: 'POST',
                body: new FormData(this.formTarget),
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            const answer = await response.json().catch(() => ({}));

            if (sequence !== this.previewSequence) {
                return;
            }

            if (!response.ok) {
                this.clearTotals();

                // The preview has its own budget: running out of it must not forbid saving what the edit limiter still allows.
                if (response.status === 429) {
                    this.showError(answer.error || '');
                    this.updateSubmit(true);

                    return;
                }

                this.updateSubmit(false);

                // Numerically equal to the stored values ("10" for "10.0"): nothing to announce.
                if (answer.refusal === 'nothing_to_edit') {
                    this.showError('');
                } else {
                    this.showError(answer.error || this.formTarget.dataset.previewFailed || response.statusText);
                }

                return;
            }

            this.showError('');
            this.fillTotals(this.beforeTargets, answer.before);
            this.fillTotals(this.afterTargets, answer.after);
            this.updateSubmit(true);
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== this.previewSequence) {
                return;
            }

            this.clearTotals();
            this.updateSubmit(false);
            this.showError(this.formTarget.dataset.previewFailed || String(error));
        }
    }

    fillTotals(cells, totals) {
        const format = new Intl.NumberFormat(this.localeValue, { style: 'currency', currency: this.currencyValue });

        cells.forEach((cell) => {
            const value = totals?.[cell.dataset.total];
            cell.textContent = value === undefined || value === null ? '–' : format.format(Number(value));
        });
    }

    clearTotals() {
        [...this.beforeTargets, ...this.afterTargets].forEach((cell) => {
            cell.textContent = '–';
        });
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = message === '';
    }

    updateSubmit(enabled) {
        this.submitTarget.disabled = !enabled;
    }

    // Pagination of the lines already on the order.

    previousPage() {
        this.showPage(this.page - 1);
    }

    nextPage() {
        this.showPage(this.page + 1);
    }

    showPage(page) {
        const lastPage = Math.max(1, Math.ceil(this.lineTargets.length / this.perPageValue));
        this.page = Math.min(Math.max(1, page), lastPage);
        const from = (this.page - 1) * this.perPageValue;

        this.lineTargets.forEach((line, index) => {
            line.hidden = index < from || index >= from + this.perPageValue;
        });

        this.pagerTarget.hidden = lastPage === 1;
        this.previousTarget.disabled = this.page === 1;
        this.nextTarget.disabled = this.page === lastPage;
        this.pageLabelTarget.textContent = this.pageLabelValue.replace('%current%', String(this.page)).replace('%last%', String(lastPage));
    }

    // Search of the combinations an order can be given.

    searchTyped() {
        clearTimeout(this.searchTimer);
        this.searchTimer = setTimeout(() => this.search(), 300);
    }

    async search() {
        const term = this.searchTarget.value.trim();
        this.searchRequest?.abort();
        const sequence = ++this.searchSequence;

        if (term.length < 2) {
            this.clearResults();

            return;
        }

        const controller = new AbortController();
        this.searchRequest = controller;

        try {
            const url = new URL(this.searchUrlValue, window.location.origin);
            url.searchParams.set('q', term);
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const rows = await response.json();

            if (sequence !== this.searchSequence) {
                return;
            }

            this.showResults(Array.isArray(rows) ? rows : []);
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== this.searchSequence) {
                return;
            }

            this.results = [];
            this.resultsTarget.replaceChildren();
            this.resultsTarget.hidden = true;
            this.setSearchStatus(this.formTarget.dataset.searchFailed || String(error));
        }
    }

    showResults(rows) {
        this.results = rows;
        this.resultsTarget.replaceChildren();

        rows.forEach((row, index) => {
            const item = this.resultTemplateTarget.content.firstElementChild.cloneNode(true);
            item.querySelector('[data-slot="title"]').textContent = row.title;
            item.querySelector('[data-slot="detail"]').textContent = this.detailOf(row);
            item.querySelector('button').dataset.resultIndex = String(index);
            this.resultsTarget.append(item);
        });

        this.resultsTarget.hidden = rows.length === 0;
        this.setSearchStatus(rows.length === 0 ? (this.formTarget.dataset.noResult || '') : '');
    }

    clearResults() {
        this.results = [];
        this.resultsTarget.replaceChildren();
        this.resultsTarget.hidden = true;
        this.setSearchStatus('');
    }

    setSearchStatus(message) {
        this.searchStatusTarget.textContent = message;
        this.searchStatusTarget.hidden = message === '';
    }

    detailOf(row) {
        return [row.pse_ref || row.product_ref, row.combination, `${this.formTarget.dataset.stockLabel || 'Stock'}: ${row.stock}`]
            .filter((part) => part)
            .join(' · ');
    }

    addProduct(event) {
        const row = this.results[Number(event.currentTarget.dataset.resultIndex)];

        if (!row) {
            return;
        }

        const index = this.addedCount++;
        const line = this.addedTemplateTarget.content.firstElementChild.cloneNode(true);
        const label = `${row.title}${row.combination ? ` (${row.combination})` : ''}`;

        line.querySelector('[data-slot="title"]').textContent = row.title;
        line.querySelector('[data-slot="detail"]').textContent = this.detailOf(row);

        line.querySelectorAll('[data-field]').forEach((field) => {
            field.name = `add[${index}][${field.dataset.field}]`;
            field.id = `order-edit-added-${index}-${field.dataset.field}`;
            field.setAttribute('value', field.dataset.field === 'pse' ? String(row.pse_id) : field.getAttribute('value') ?? '');
            field.defaultValue = field.getAttribute('value') ?? '';
        });

        ['quantity', 'price'].forEach((name) => {
            const labelElement = line.querySelector(`[data-slot="${name}-label"]`);
            labelElement.htmlFor = `order-edit-added-${index}-${name}`;
            labelElement.textContent = `${labelElement.textContent} — ${label}`;
        });

        this.addedTarget.append(line);
        this.addedWrapperTarget.hidden = false;
        line.querySelector('[data-field="quantity"]').focus();
        this.clearResults();
        this.searchTarget.value = '';
        this.preview();
    }

    removeAdded(event) {
        event.currentTarget.closest('tr').remove();
        this.addedWrapperTarget.hidden = this.addedTarget.children.length === 0;
        this.searchTarget.focus();
        this.preview();
    }
}
