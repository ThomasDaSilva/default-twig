import { Controller } from '@hotwired/stimulus';

/**
 * Dual-thumb range slider bound to two numeric inputs.
 * The text inputs stay the form's source of truth; the slider is a visual aid.
 * Emptying an input or moving the thumb back to an extremum clears the field.
 */
export default class extends Controller {
    static targets = ['minRange', 'maxRange', 'minInput', 'maxInput', 'fill'];

    static values = {
        min: Number,
        max: Number,
        step: { type: Number, default: 1 },
    };

    connect() {
        this.sync();
    }

    onMinRange() {
        if (Number(this.minRangeTarget.value) > Number(this.maxRangeTarget.value)) {
            this.maxRangeTarget.value = this.minRangeTarget.value;
        }
        this.writeInputs();
        this.updateFill();
    }

    onMaxRange() {
        if (Number(this.maxRangeTarget.value) < Number(this.minRangeTarget.value)) {
            this.minRangeTarget.value = this.maxRangeTarget.value;
        }
        this.writeInputs();
        this.updateFill();
    }

    onMinInput() {
        const value = this.parseInput(this.minInputTarget.value);
        this.minRangeTarget.value = value !== null ? this.clamp(value) : this.minValue;
        if (Number(this.minRangeTarget.value) > Number(this.maxRangeTarget.value)) {
            this.maxRangeTarget.value = this.minRangeTarget.value;
            this.maxInputTarget.value = this.maxRangeTarget.value;
        }
        this.updateFill();
    }

    onMaxInput() {
        const value = this.parseInput(this.maxInputTarget.value);
        this.maxRangeTarget.value = value !== null ? this.clamp(value) : this.maxValue;
        if (Number(this.maxRangeTarget.value) < Number(this.minRangeTarget.value)) {
            this.minRangeTarget.value = this.maxRangeTarget.value;
            this.minInputTarget.value = this.minRangeTarget.value;
        }
        this.updateFill();
    }

    sync() {
        const minInput = this.parseInput(this.minInputTarget.value);
        const maxInput = this.parseInput(this.maxInputTarget.value);
        this.minRangeTarget.value = minInput !== null ? this.clamp(minInput) : this.minValue;
        this.maxRangeTarget.value = maxInput !== null ? this.clamp(maxInput) : this.maxValue;
        this.updateFill();
    }

    writeInputs() {
        const min = Number(this.minRangeTarget.value);
        const max = Number(this.maxRangeTarget.value);
        this.minInputTarget.value = min === this.minValue ? '' : String(min);
        this.maxInputTarget.value = max === this.maxValue ? '' : String(max);
    }

    updateFill() {
        const span = this.maxValue - this.minValue;
        if (span <= 0) {
            return;
        }
        const minPercent = ((Number(this.minRangeTarget.value) - this.minValue) / span) * 100;
        const maxPercent = ((Number(this.maxRangeTarget.value) - this.minValue) / span) * 100;
        // Logical insets, not left/right: a native range input runs from its max
        // to its min under dir="rtl", so the painted fill has to follow the
        // reading direction the same way.
        this.fillTarget.style.insetInlineStart = `${minPercent}%`;
        this.fillTarget.style.insetInlineEnd = `${100 - maxPercent}%`;
    }

    clamp(value) {
        return Math.max(this.minValue, Math.min(value, this.maxValue));
    }

    parseInput(value) {
        if (value == null || value === '') {
            return null;
        }
        const normalised = String(value).replace(',', '.').replace(/\s/g, '');
        const num = Number(normalised);
        return Number.isFinite(num) ? num : null;
    }
}
