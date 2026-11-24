import { Controller } from '@hotwired/stimulus';

/**
 * Lets a form leave once: a second submission is cancelled and the submit button is
 * disabled, so that a double click never sends the same action twice. Runs on the
 * `submit` event, after the native validation, so an invalid form stays editable.
 * The button is enabled again when the browser restores the page from its history.
 */
export default class extends Controller {
    connect() {
        this.sent = false;
    }

    lock(event) {
        if (this.sent) {
            event.preventDefault();

            return;
        }

        this.sent = true;
        // Disabling inside the submit event is safe: the submission is already under way.
        // The button's own name/value is not posted by this form, so nothing is lost.
        this.element.querySelectorAll('[type="submit"]').forEach((button) => {
            button.disabled = true;
        });
    }

    unlock() {
        this.sent = false;
        this.element.querySelectorAll('[type="submit"]').forEach((button) => {
            button.disabled = false;
        });
    }
}
