import { Controller } from '@hotwired/stimulus';

// Brings the element into view when the page opens, for a link that points at one row of a long page.
export default class extends Controller {
    connect() {
        this.element.scrollIntoView({ block: 'center', behavior: 'auto' });
    }
}
