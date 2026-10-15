import { Controller } from '@hotwired/stimulus';

/**
 * Searches for an item to relate (a product by reference or title, a content by
 * title) in one field. The server answers with the items this block can still
 * take, so the edited object itself and what is already related never show up.
 *
 * Picking a suggestion only fills the hidden field the form posts and enables the
 * add button: the relation is written when the operator clicks +, so a stray
 * click in the list adds nothing. Typing again clears the pick.
 */
export default class extends Controller {
    static targets = ['input', 'value', 'submit', 'menu'];

    static values = {
        url: String,
        emptyLabel: String,
    };

    connect() {
        this.highlightedIndex = -1;
        this.items = [];
        this.debounce = null;
        this.abort = null;
    }

    disconnect() {
        window.clearTimeout(this.debounce);
        this.abort?.abort();
        this.closeMenu();
    }

    // ---------- actions ----------

    search() {
        this.unpick();
        window.clearTimeout(this.debounce);

        const term = this.inputTarget.value.trim();
        if (term === '') {
            this.abort?.abort();
            this.items = [];
            this.closeMenu();

            return;
        }

        this.debounce = window.setTimeout(() => this.load(term), 200);
    }

    open() {
        if (this.inputTarget.value.trim() !== '' && this.valueTarget.value === '') {
            this.render();
        }
    }

    close() {
        this.closeMenu();
    }

    key(event) {
        const options = Array.from(this.menuTarget.querySelectorAll('[data-selectable]'));

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (options.length === 0) {
                return;
            }
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.highlight((this.highlightedIndex + step + options.length) % options.length);

            return;
        }

        if (event.key === 'Enter') {
            // A picked product lets Enter submit, as the + button would; otherwise
            // Enter picks the highlighted suggestion and never posts an empty form.
            if (this.valueTarget.value !== '') {
                return;
            }
            event.preventDefault();
            const option = options[this.highlightedIndex];
            if (option) {
                option.dispatchEvent(new MouseEvent('mousedown'));
            }

            return;
        }

        if (event.key === 'Escape') {
            this.closeMenu();
        }
    }

    // ---------- search ----------

    async load(term) {
        this.abort?.abort();
        this.abort = new AbortController();

        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('q', term);

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: this.abort.signal,
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const items = await response.json();
            this.items = Array.isArray(items) ? items : [];
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            this.items = [];
        }

        this.render();
    }

    // ---------- rendering ----------

    render() {
        this.menuTarget.replaceChildren();

        this.items.forEach((item) => {
            this.menuTarget.append(this.buildOption(item));
        });

        if (this.items.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'bo-tag-picker__empty';
            empty.textContent = this.emptyLabelValue;
            this.menuTarget.append(empty);
        }

        this.openMenu();
        this.highlight(this.items.length === 0 ? -1 : 0);
    }

    buildOption(item) {
        const option = document.createElement('li');
        option.className = 'bo-tag-picker__option';
        option.dataset.selectable = 'true';
        option.setAttribute('role', 'option');
        option.textContent = this.label(item);
        // mousedown rather than click: the input loses focus first on a click, and
        // the blur handler would have closed the menu before the choice landed.
        option.addEventListener('mousedown', (event) => {
            event.preventDefault();
            this.pick(item);
        });

        return option;
    }

    label(item) {
        if (!item.title) {
            return item.ref;
        }

        return item.ref ? `${item.title} (${item.ref})` : item.title;
    }

    highlight(index) {
        this.highlightedIndex = index;
        this.menuTarget.querySelectorAll('[data-selectable]').forEach((option, position) => {
            option.classList.toggle('bo-tag-picker__option--active', position === index);
        });
    }

    // ---------- state ----------

    pick(item) {
        this.valueTarget.value = String(item.id);
        this.inputTarget.value = this.label(item);
        this.submitTarget.disabled = false;
        this.closeMenu();
    }

    unpick() {
        this.valueTarget.value = '';
        this.submitTarget.disabled = true;
    }

    openMenu() {
        this.menuTarget.classList.remove('d-none');
        this.inputTarget.setAttribute('aria-expanded', 'true');
    }

    closeMenu() {
        this.menuTarget.classList.add('d-none');
        this.inputTarget.setAttribute('aria-expanded', 'false');
    }
}
