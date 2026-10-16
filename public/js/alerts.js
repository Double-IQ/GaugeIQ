const metric = document.getElementById('metric');
const condition = document.getElementById('condition_type');
const valueField = document.getElementById('valueField');
const valueInput = document.getElementById('value');
const degreesField = document.getElementById('degreesField');
const speedField = document.getElementById('speedField');
const fromField = document.getElementById('fromField');

function refreshFields() {
    const m = metric.value;
    const c = condition.value;
    const direction = m === 'wind_direction' && c === 'specific';
    const combined = m === 'wind' && c === 'speed_and_direction';
    const weatherVolatility = m === 'weather_stability';

    degreesField.hidden = !direction;
    valueField.hidden = direction || combined;
    speedField.hidden = !combined;
    fromField.hidden = !combined;

    if (valueInput) {
        if (weatherVolatility) {
            valueInput.min = '0';
            valueInput.max = '10';
            valueInput.step = '1';
        } else if (m === 'humidity') {
            valueInput.min = '0';
            valueInput.max = '100';
            valueInput.step = '1';
        } else if (m === 'wind_speed') {
            valueInput.min = '0';
            valueInput.max = '500';
            valueInput.step = '0.1';
        } else {
            valueInput.min = '0';
            valueInput.removeAttribute('max');
            valueInput.step = '0.1';
        }
    }

    if (weatherVolatility) {
        condition.value = 'above';
        valueField.hidden = false;
        const aboveOption = [...condition.options].find(option => option.value === 'above');
        if (aboveOption) aboveOption.textContent = 'Rises to or above';
        const help = document.getElementById('weatherVolatilityHelp');
        if (help) help.hidden = false;
    } else {
        const aboveOption = [...condition.options].find(option => option.value === 'above');
        if (aboveOption) aboveOption.textContent = 'Rises above';
        const help = document.getElementById('weatherVolatilityHelp');
        if (help) help.hidden = true;
    }

    [...condition.options].forEach(option => {
        option.hidden =
            m === 'weather_stability'
                ? option.value !== 'above'
                : m === 'wind_direction'
                    ? !['change', 'specific'].includes(option.value)
                    : m === 'wind'
                        ? option.value !== 'speed_and_direction'
                        : ['specific', 'speed_and_direction'].includes(option.value);
    });

    if (m === 'wind') {
        condition.value = 'speed_and_direction';
    } else if (m === 'weather_stability') {
        condition.value = 'above';
    } else if (m === 'wind_direction' && !['change', 'specific'].includes(condition.value)) {
        condition.value = 'change';
    } else if (m !== 'wind_direction' && ['specific', 'speed_and_direction'].includes(condition.value)) {
        condition.value = 'change';
    }
}

metric.addEventListener('change', refreshFields);
condition.addEventListener('change', refreshFields);
refreshFields();


const compassChoices = document.querySelectorAll('.compass-choice');
const degreesInput = document.getElementById('degrees');

function refreshCompass() {
    compassChoices.forEach(button => {
        button.classList.toggle('selected', button.dataset.degrees === degreesInput.value);
    });
}

compassChoices.forEach(button => {
    button.addEventListener('click', () => {
        degreesInput.value = button.dataset.degrees;
        refreshCompass();
    });
});

refreshCompass();


function setupAlertEditing() {
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-edit-alert]');
        if (!button) return;
        const card = button.closest('[data-alert-card]');
        const form = card?.querySelector('[data-edit-form]');
        if (!card || !form) return;
        event.preventDefault();
        form.hidden = false;
        button.hidden = true;
        card.classList.add('editing');
    });

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-cancel-edit]');
        if (!button) return;
        const card = button.closest('[data-alert-card]');
        const form = card?.querySelector('[data-edit-form]');
        const editButton = card?.querySelector('[data-edit-alert]');
        if (!card || !form || !editButton) return;
        event.preventDefault();
        form.hidden = true;
        editButton.hidden = false;
        card.classList.remove('editing');
    });

    document.querySelectorAll('[data-edit-form]').forEach(form => {
        const metric = form.querySelector('[data-edit-metric]');
        const condition = form.querySelector('[data-edit-condition]');
        const valueField = form.querySelector('[data-edit-value-field]');
        const valueInput = form.querySelector('input[name="value"]');
        const degreesField = form.querySelector('[data-edit-degrees-field]');
        const speedField = form.querySelector('[data-edit-speed-field]');
        const fromField = form.querySelector('[data-edit-from-field]');
        const toField = form.querySelector('[data-edit-to-field]');
        if (!metric || !condition) return;

        const refresh = () => {
            const isWind = metric.value === 'wind';
            const isDirection = metric.value === 'wind_direction';
            const isWeatherStability = metric.value === 'weather_stability';
            const combined = isWind && condition.value === 'speed_and_direction';

            degreesField.hidden = !(isDirection && condition.value === 'specific');
            valueField.hidden = isDirection || combined;
            speedField.hidden = !combined;
            fromField.hidden = !combined;

            if (valueInput) {
                if (isWeatherStability) {
                    valueInput.min = '0';
                    valueInput.max = '10';
                    valueInput.step = '1';
                } else if (metric.value === 'humidity') {
                    valueInput.min = '0';
                    valueInput.max = '100';
                    valueInput.step = '1';
                } else if (metric.value === 'wind_speed') {
                    valueInput.min = '0';
                    valueInput.max = '500';
                    valueInput.step = '0.1';
                } else {
                    valueInput.min = '0';
                    valueInput.removeAttribute('max');
                    valueInput.step = '0.1';
                }
            }

            if (isWeatherStability) {
                condition.value = 'above';
                valueField.hidden = false;
                const aboveOption = [...condition.options].find(option => option.value === 'above');
                if (aboveOption) aboveOption.textContent = 'Rises to or above';
            } else {
                const aboveOption = [...condition.options].find(option => option.value === 'above');
                if (aboveOption) aboveOption.textContent = 'Rises above';
            }
            toField.hidden = !combined;

            [...condition.options].forEach(option => {
                option.hidden = isWeatherStability
                    ? option.value !== 'above'
                    : isDirection
                        ? !['change', 'specific'].includes(option.value)
                        : isWind
                            ? option.value !== 'speed_and_direction'
                            : ['specific', 'speed_and_direction'].includes(option.value);
            });

            if (isWeatherStability) {
                condition.value = 'above';
            } else if (isWind) {
                condition.value = 'speed_and_direction';
            } else if (isDirection && !['change', 'specific'].includes(condition.value)) {
                condition.value = 'change';
            } else if (!isDirection && ['specific', 'speed_and_direction'].includes(condition.value)) {
                condition.value = 'change';
            }
        };

        metric.addEventListener('change', refresh);
        condition.addEventListener('change', refresh);
        refresh();
    });
}

setupAlertEditing();
