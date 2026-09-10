import { Controller } from '@hotwired/stimulus';

/**
 * The "add an action" dialog of an order status: shows the fields of the chosen
 * action type and the "coming from" status only for a transition trigger. Fields
 * of the other types are disabled so that the form only posts what applies.
 */
export default class extends Controller {
    static targets = ['type', 'fields', 'trigger', 'from'];

    connect() {
        this.refresh();
    }

    refresh() {
        const type = this.hasTypeTarget ? this.typeTarget.value : '';

        this.fieldsTargets.forEach((block) => {
            const applies = block.dataset.type === type;
            block.hidden = !applies;
            block.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = !applies;
            });
        });

        const transition = this.triggerTargets.some((radio) => radio.checked && radio.value === 'transition');
        this.fromTargets.forEach((block) => {
            block.hidden = !transition;
            block.querySelectorAll('select').forEach((field) => {
                field.disabled = !transition;
            });
        });
    }
}
