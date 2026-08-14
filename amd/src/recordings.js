// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

import Ajax from 'core/ajax';
import Notification from 'core/notification';

const SELECTORS = {
    row: '[data-region="recording-row"]',
    nameDisplay: '[data-region="name-display"]',
    nameForm: '[data-region="name-form"]',
    name: '[data-region="recording-name"]',
    visibilityLabel: '[data-region="visibility-label"]',
    emptyState: '[data-region="empty-state"]',
    deleteContainer: '[data-region="delete-container"]',
};

/**
 * Calls one Moodle external function.
 *
 * @param {String} methodname External function name.
 * @param {Object} args Validated function arguments.
 * @returns {Promise}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Disables or enables all controls belonging to a recording row.
 *
 * @param {HTMLElement} row Recording row.
 * @param {Boolean} busy Whether the row is busy.
 */
const setRowBusy = (row, busy) => {
    row.setAttribute('aria-busy', busy ? 'true' : 'false');
    row.querySelectorAll('button, input').forEach(control => {
        control.disabled = busy;
    });
};

/**
 * Restores the display state for a recording name.
 *
 * @param {HTMLElement} row Recording row.
 * @param {Boolean} focusEditor Whether to return focus to the edit button.
 */
const closeNameForm = (row, focusEditor = true) => {
    const display = row.querySelector(SELECTORS.nameDisplay);
    const form = row.querySelector(SELECTORS.nameForm);
    if (!display || !form) {
        return;
    }

    form.hidden = true;
    display.hidden = false;
    const input = form.querySelector('input[name="name"]');
    const name = row.querySelector(SELECTORS.name);
    if (input && name) {
        input.value = name.textContent;
        input.setCustomValidity('');
    }
    if (focusEditor) {
        display.querySelector('[data-action="edit-name"]')?.focus();
    }
};

/**
 * Opens the inline rename form without creating HTML from stored values.
 *
 * @param {HTMLElement} row Recording row.
 */
const openNameForm = row => {
    const display = row.querySelector(SELECTORS.nameDisplay);
    const form = row.querySelector(SELECTORS.nameForm);
    const input = form?.querySelector('input[name="name"]');
    if (!display || !form || !input) {
        return;
    }

    display.hidden = true;
    form.hidden = false;
    input.focus();
    input.select();
};

/**
 * Persists a recording name.
 *
 * @param {SubmitEvent} event Form submission.
 * @param {Object} config Module configuration.
 */
const renameRecording = (event, config) => {
    event.preventDefault();
    const form = event.target;
    const row = form.closest(SELECTORS.row);
    const input = form.querySelector('input[name="name"]');
    const name = input?.value.trim() ?? '';
    if (!row || !input) {
        return;
    }
    if (name === '') {
        input.setCustomValidity(config.strings.invalidRecordingName);
        input.reportValidity();
        return;
    }

    input.setCustomValidity('');
    setRowBusy(row, true);
    call('mod_googlemeet_rename_recording', {
        recordingid: Number(row.dataset.recordingId),
        name,
        coursemoduleid: config.coursemoduleid,
    }).then(response => {
        const nameNode = row.querySelector(SELECTORS.name);
        if (nameNode) {
            nameNode.textContent = response.name;
        }
        setRowBusy(row, false);
        closeNameForm(row);
    }).catch(error => {
        setRowBusy(row, false);
        input.focus();
        Notification.exception(error);
    });
};

/**
 * Persists an explicit participant visibility value.
 *
 * @param {HTMLButtonElement} button Visibility button.
 * @param {Object} config Module configuration.
 */
const setVisibility = (button, config) => {
    const row = button.closest(SELECTORS.row);
    if (!row) {
        return;
    }

    const visible = button.dataset.visible === '1';
    setRowBusy(row, true);
    call('mod_googlemeet_set_recording_visibility', {
        recordingid: Number(row.dataset.recordingId),
        visible,
        coursemoduleid: config.coursemoduleid,
    }).then(response => {
        const persisted = Boolean(response.visible);
        row.classList.toggle('googlemeet-recording-hidden', !persisted);
        button.dataset.visible = persisted ? '0' : '1';
        const label = button.querySelector(SELECTORS.visibilityLabel);
        if (label) {
            label.textContent = persisted ? config.strings.hide : config.strings.show;
        }
    }).catch(Notification.exception).finally(() => {
        setRowBusy(row, false);
    });
};

/**
 * Removes all local recording references after an explicit confirmation.
 *
 * Provider files are intentionally outside this operation.
 *
 * @param {HTMLButtonElement} button Delete button.
 * @param {HTMLElement} root Module root.
 * @param {Object} config Module configuration.
 */
const deleteRecordings = (button, root, config) => {
    Notification.deleteCancel(
        config.strings.deleteTitle,
        config.strings.deleteQuestion,
        config.strings.deleteButton,
        () => {
            button.disabled = true;
            root.setAttribute('aria-busy', 'true');
            call('mod_googlemeet_delete_recordings', {
                coursemoduleid: config.coursemoduleid,
            }).then(() => {
                root.querySelectorAll(SELECTORS.row).forEach(row => row.remove());
                const emptyState = root.querySelector(SELECTORS.emptyState);
                const deleteContainer = root.querySelector(SELECTORS.deleteContainer);
                if (emptyState) {
                    emptyState.hidden = false;
                }
                if (deleteContainer) {
                    deleteContainer.hidden = true;
                }
            }).catch(error => {
                button.disabled = false;
                Notification.exception(error);
            }).finally(() => {
                root.setAttribute('aria-busy', 'false');
            });
        },
        null,
        {triggerElement: button}
    );
};

/**
 * Enables the read-only searchable recording table.
 *
 * @param {HTMLElement} root Module root.
 * @param {Object} strings Localized strings.
 */
const enableSearch = (root, strings) => {
    if (typeof window.JSTable !== 'function') {
        return;
    }

    new window.JSTable(`#${root.id} table`, {
        sortable: false,
        searchable: true,
        perPage: 5,
        perPageSelect: false,
        labels: {
            placeholder: strings.jstablePlaceholder,
            perPage: strings.jstablePerPage,
            noRows: strings.jstableNoRows,
            info: strings.jstableInfo,
            loading: strings.jstableLoading,
            infoFiltered: strings.jstableInfoFiltered,
        },
    });
};

/**
 * Initializes recording presentation and mutations for one activity.
 *
 * @param {Object} config Server-provided configuration.
 */
export const init = config => {
    const root = document.getElementById(config.rootId);
    if (!root) {
        return;
    }

    if (config.searchable) {
        enableSearch(root, config.strings);
    }

    root.addEventListener('submit', event => {
        if (event.target.matches(SELECTORS.nameForm)) {
            renameRecording(event, config);
        }
    });

    root.addEventListener('click', event => {
        const button = event.target.closest('button[data-action]');
        if (!button || !root.contains(button)) {
            return;
        }

        const row = button.closest(SELECTORS.row);
        switch (button.dataset.action) {
            case 'edit-name':
                if (row) {
                    openNameForm(row);
                }
                break;
            case 'cancel-name':
                if (row) {
                    closeNameForm(row);
                }
                break;
            case 'set-visibility':
                setVisibility(button, config);
                break;
            case 'delete-recordings':
                deleteRecordings(button, root, config);
                break;
        }
    });

    root.addEventListener('keydown', event => {
        if (event.key !== 'Escape') {
            return;
        }
        const form = event.target.closest(SELECTORS.nameForm);
        const row = form?.closest(SELECTORS.row);
        if (row) {
            event.preventDefault();
            closeNameForm(row);
        }
    });
};
