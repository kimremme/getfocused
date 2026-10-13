const form = document.querySelector('#pamelding form');

if (form) {
    const submitButton = form.querySelector('button[type="submit"]');
    const registrationHeading = document.querySelector('.registration-heading');
    const allergyCheckboxes = [...form.querySelectorAll('[name="allergies[]"]')];
    const selectAllergies = form.querySelector('#select-allergies');

    function updateSelectAll() {
        const selectedCount = allergyCheckboxes.filter((checkbox) => checkbox.checked).length;
        selectAllergies.checked = selectedCount === allergyCheckboxes.length;
        selectAllergies.indeterminate = selectedCount > 0 && selectedCount < allergyCheckboxes.length;
    }

    allergyCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelectAll));
    selectAllergies.addEventListener('change', () => {
        allergyCheckboxes.forEach((checkbox) => {
            checkbox.checked = selectAllergies.checked;
        });
        selectAllergies.indeterminate = false;
    });
    form.addEventListener('reset', () => {
        requestAnimationFrame(updateSelectAll);
    });
    updateSelectAll();

    function createThankYouCard(message) {
        const card = document.createElement('div');
        card.className = 'thank-you-card';
        card.id = 'registration-thanks';
        card.tabIndex = -1;
        card.setAttribute('role', 'status');
        card.setAttribute('aria-live', 'polite');

        const icon = document.createElement('span');
        icon.className = 'thank-you-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '✓';

        const eyebrow = document.createElement('p');
        eyebrow.className = 'thank-you-eyebrow';
        eyebrow.textContent = 'PÅMELDINGEN ER REGISTRERT';

        const heading = document.createElement('h3');
        heading.textContent = 'Takk for påmeldingen!';

        const description = document.createElement('p');
        description.textContent = message || 'Vi gleder oss til å dele Get Focused med deg. Du hører fra oss snart med mer informasjon.';

        const anotherLink = document.createElement('a');
        anotherLink.className = 'button button-dark';
        anotherLink.href = '?new=1#pamelding';
        anotherLink.dataset.registerAnother = 'true';
        anotherLink.append(document.createTextNode('Meld på en deltaker til '));

        const arrow = document.createElement('span');
        arrow.setAttribute('aria-hidden', 'true');
        arrow.textContent = '↗';
        anotherLink.append(arrow);

        card.append(icon, eyebrow, heading, description, anotherLink);
        return card;
    }

    function showRegistrationForm() {
        document.querySelector('#registration-thanks')?.remove();
        form.hidden = false;
        document.querySelector('.required-note').hidden = false;
        history.replaceState(null, '', `${location.pathname}${location.hash || '#pamelding'}`);
        form.querySelector('[name="name"]').focus();
    }

    document.addEventListener('click', (event) => {
        const anotherLink = event.target.closest('[data-register-another]');
        if (!anotherLink) {
            return;
        }

        event.preventDefault();
        showRegistrationForm();
    });

    function clearAjaxErrors() {
        const errors = [...form.querySelectorAll('.field-error')];
        const errorIds = new Set(errors.map((error) => error.id).filter(Boolean));
        errors.forEach((error) => error.remove());
        form.querySelectorAll('[aria-invalid="true"], [aria-describedby]').forEach((field) => {
            field.removeAttribute('aria-invalid');
            const describedBy = field.getAttribute('aria-describedby');
            if (describedBy) {
                const ids = describedBy.split(/\s+/).filter((id) => !errorIds.has(id));
                if (ids.length) {
                    field.setAttribute('aria-describedby', ids.join(' '));
                } else {
                    field.removeAttribute('aria-describedby');
                }
            }
        });
    }

    function showFormMessage(message, type) {
        let notice = document.querySelector('#registration-response');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'registration-response';
            notice.setAttribute('role', 'status');
            notice.setAttribute('aria-live', 'polite');
            registrationHeading.insertAdjacentElement('afterend', notice);
        }
        notice.className = `notice notice-${type}`;
        notice.textContent = message;
        notice.hidden = false;
    }

    function showFieldError(name, message) {
        if (name === 'form') {
            return;
        }

        const field = form.querySelector(`[name="${name}"], [name="${name}[]"]`);
        if (!field) {
            return;
        }

        const container = field.closest('.field');
        if (!container) {
            return;
        }

        const error = document.createElement('span');
        error.className = 'field-error';
        error.dataset.ajaxError = 'true';
        error.id = `${name}-ajax-error`;
        error.textContent = message;
        container.append(error);

        form.querySelectorAll(`[name="${name}"], [name="${name}[]"]`).forEach((control) => {
            control.setAttribute('aria-invalid', 'true');
            const describedBy = control.getAttribute('aria-describedby');
            const ids = describedBy ? describedBy.split(/\s+/) : [];
            if (!ids.includes(error.id)) {
                ids.push(error.id);
            }
            control.setAttribute('aria-describedby', ids.join(' '));
        });
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearAjaxErrors();

        const responseNotice = document.querySelector('#registration-response');
        if (responseNotice) {
            responseNotice.hidden = true;
        }

        const buttonContents = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.textContent = 'Sender …';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = await response.json();

            if (result.csrfToken) {
                form.querySelector('[name="csrf_token"]').value = result.csrfToken;
            }

            if (response.ok && result.success) {
                form.reset();
                if (result.csrfToken) {
                    form.querySelector('[name="csrf_token"]').value = result.csrfToken;
                }
                document.querySelector('#registration-response')?.remove();
                form.hidden = true;
                document.querySelector('.required-note').hidden = true;
                const thankYouCard = createThankYouCard(result.message);
                registrationHeading.insertAdjacentElement('afterend', thankYouCard);
                thankYouCard.focus();
                return;
            }

            Object.entries(result.errors || {}).forEach(([name, message]) => {
                if (name !== 'form') {
                    showFieldError(name, message);
                }
            });

            if (result.message) {
                showFormMessage(result.message, 'error');
            } else {
                showFormMessage('Kontroller feltene som er markert, og prøv igjen.', 'error');
            }

            const firstInvalidField = form.querySelector('[aria-invalid="true"]');
            if (firstInvalidField) {
                firstInvalidField.focus();
            }
        } catch (error) {
            showFormMessage('Kunne ikke sende påmeldingen. Kontroller internettforbindelsen og prøv igjen.', 'error');
        } finally {
            submitButton.disabled = false;
            submitButton.innerHTML = buttonContents;
        }
    });
}
