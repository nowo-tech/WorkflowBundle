/*
 * NowoWorkflowBundle admin UI behaviour (REQ-UI-003): no framework JS, no inline handlers, no confirm().
 *
 * - Dialogs: the UiKit `dialog` partial and js/nowo-ui-dialog.js open / close them (data-nowo-ui-dialog-open,
 *   data-nowo-ui-dialog-close, Esc, backdrop click). An opener with data-workflow-dialog-swap closes the dialog it
 *   sits in first (place → transition in the flow diagram), so one dialog is open at a time.
 * - Flow diagram: hovering / focusing a transition label highlights its own connector (shared data-edge).
 * - Collection editors ([data-workflow-collection]): table of the CollectionType entries, add / edit in the UiKit
 *   dialog (the entry's fields move into it), remove behind the UiKit confirm dialog, optional TomSelect
 *   multiselects (loaded by the transitions page from the bundle's vendor/tom-select assets).
 */
(function () {
    'use strict';

    function openDialog(dialog) {
        if (dialog instanceof HTMLDialogElement && !dialog.open && typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
    }

    function closeDialog(dialog) {
        if (dialog instanceof HTMLDialogElement && dialog.open) {
            dialog.close();
        }
    }

    // Capture phase: runs before the UiKit opener (bubble phase on document) opens the target dialog.
    document.addEventListener('click', function (event) {
        var target = event.target;
        var opener = target instanceof Element ? target.closest('[data-workflow-dialog-swap][data-nowo-ui-dialog-open]') : null;
        if (opener) {
            closeDialog(opener.closest('dialog'));
        }
    }, true);

    function highlightEdge(event, active) {
        var target = event.target;
        var box = target instanceof Element ? target.closest('.nowo-workflow-diagram__label-box[data-edge]') : null;
        var svg = box ? box.closest('svg') : null;
        var edge = svg ? svg.querySelector('.nowo-workflow-diagram__edge[data-edge="' + box.getAttribute('data-edge') + '"]') : null;
        if (edge) {
            edge.classList.toggle('is-active', active);
        }
    }

    ['mouseover', 'focusin'].forEach(function (type) {
        document.addEventListener(type, function (event) { highlightEdge(event, true); });
    });
    ['mouseout', 'focusout'].forEach(function (type) {
        document.addEventListener(type, function (event) { highlightEdge(event, false); });
    });

    var tomSelects = new WeakMap();

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fieldValue(entry, field) {
        var select = entry.querySelector('[name$="[' + field + '][]"]');
        if (select) {
            var instance = tomSelects.get(select);
            if (instance) {
                return instance.getValue();
            }

            return Array.prototype.map.call(select.selectedOptions, function (option) {
                return option.value;
            });
        }

        var input = entry.querySelector('[name$="[' + field + ']"]');
        if (!input) {
            return '';
        }
        if (input.type === 'checkbox') {
            return input.checked ? '1' : '';
        }

        return input.value.trim();
    }

    function setFieldValue(entry, field, value) {
        var select = entry.querySelector('[name$="[' + field + '][]"]');
        if (select) {
            var values = Array.isArray(value) ? value : [];
            var instance = tomSelects.get(select);
            if (instance) {
                instance.setValue(values, true);

                return;
            }
            Array.prototype.forEach.call(select.options, function (option) {
                option.selected = values.indexOf(option.value) !== -1;
            });

            return;
        }

        var input = entry.querySelector('[name$="[' + field + ']"]');
        if (input) {
            if (input.type === 'checkbox') {
                input.checked = value === '1';
            } else {
                input.value = value == null ? '' : value;
            }
        }
    }

    function snapshot(entry) {
        var state = [];
        entry.querySelectorAll('[name]').forEach(function (input) {
            var field = (input.name.match(/\[([^\[\]]+)\](\[\])?$/) || [])[1];
            if (field) {
                state.push([field, fieldValue(entry, field)]);
            }
        });

        return state;
    }

    function restore(entry, state) {
        (state || []).forEach(function (pair) {
            setFieldValue(entry, pair[0], pair[1]);
        });
    }

    function destroyTomSelects(entry) {
        entry.querySelectorAll('.nowo-place-multiselect').forEach(function (select) {
            var instance = tomSelects.get(select);
            if (instance) {
                instance.destroy();
                tomSelects.delete(select);
            }
        });
    }

    function initTomSelects(entry, placeNames, placeholder) {
        if (typeof window.TomSelect === 'undefined') {
            return;
        }

        entry.querySelectorAll('.nowo-place-multiselect').forEach(function (select) {
            var selected = Array.prototype.map.call(select.selectedOptions, function (option) {
                return option.value;
            });
            var names = [];
            placeNames.concat(selected).forEach(function (name) {
                if (names.indexOf(name) === -1) {
                    names.push(name);
                }
            });

            select.innerHTML = '';
            names.forEach(function (name) {
                var option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                option.selected = selected.indexOf(name) !== -1;
                select.appendChild(option);
            });

            tomSelects.set(select, new window.TomSelect(select, {
                plugins: ['remove_button'],
                persist: false,
                create: false,
                maxItems: null,
                placeholder: placeholder,
                hideSelected: true,
                closeAfterSelect: false,
            }));
        });
    }

    function reindex(root) {
        var prefix = root.getAttribute('data-form-prefix') || '';
        var pattern = new RegExp(prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\[\\d+\\]');
        var entries = root.querySelectorAll('[data-collection-entry]');

        entries.forEach(function (entry, index) {
            entry.setAttribute('data-collection-entry', String(index));
            entry.querySelectorAll('[name]').forEach(function (input) {
                input.name = input.name.replace(pattern, prefix + '[' + index + ']');
            });
            entry.querySelectorAll('[id]').forEach(function (element) {
                element.id = element.id.replace(/_\d+_/, '_' + index + '_');
            });
            entry.querySelectorAll('label[for]').forEach(function (label) {
                label.htmlFor = label.htmlFor.replace(/_\d+_/, '_' + index + '_');
            });
        });

        root.setAttribute('data-index', String(entries.length));
    }

    function formatCell(value, format) {
        if (Array.isArray(value)) {
            return value.length === 0 ? '—' : value.map(function (item) {
                return '<code>' + escapeHtml(item) + '</code>';
            }).join(', ');
        }
        if (value === '') {
            return '—';
        }

        return format === 'code' ? '<code>' + escapeHtml(value) + '</code>' : escapeHtml(value);
    }

    function createCollection(root) {
        var columns = JSON.parse(root.getAttribute('data-columns') || '[]');
        var placeNames = JSON.parse(root.getAttribute('data-place-names') || '[]');
        var placeholder = root.getAttribute('data-place-placeholder') || '';
        var dialog = document.getElementById(root.getAttribute('data-dialog-id') || '');
        var removeDialog = document.getElementById(root.getAttribute('data-remove-dialog-id') || '');
        var rowTemplate = root.querySelector('template[data-collection-row]');
        var tbody = root.querySelector('[data-collection-table]');
        var emptyState = root.querySelector('[data-collection-empty]');
        var entriesHost = root.querySelector('[data-collection-entries]');
        if (!dialog || !rowTemplate || !tbody || !entriesHost) {
            return;
        }

        var dialogBody = dialog.querySelector('[data-dialog-body]');
        var dialogTitle = document.getElementById(dialog.id + '-title');
        var active = null;
        var state = null;
        var isNew = false;
        var pendingRemoval = null;

        function rebuild() {
            var entries = root.querySelectorAll('[data-collection-entries] [data-collection-entry]');
            tbody.innerHTML = '';
            entries.forEach(function (entry, index) {
                var row = rowTemplate.content.firstElementChild.cloneNode(true);
                var cellTemplate = row.querySelector('[data-collection-cell]');
                var actionsCell = cellTemplate.nextElementSibling;
                cellTemplate.remove();
                columns.forEach(function (column) {
                    var cell = cellTemplate.cloneNode(false);
                    cell.removeAttribute('data-collection-cell');
                    cell.setAttribute('data-label', column.label);
                    cell.innerHTML = formatCell(fieldValue(entry, column.field), column.format);
                    row.insertBefore(cell, actionsCell);
                });
                row.querySelector('[data-row-edit]').setAttribute('data-row-edit', String(index));
                row.querySelector('[data-row-delete]').setAttribute('data-row-delete', String(index));
                tbody.appendChild(row);
            });
            if (emptyState) {
                emptyState.hidden = entries.length > 0;
            }
        }

        function finish(apply) {
            if (!active) {
                return;
            }
            var entry = active;
            active = null;
            destroyTomSelects(entry);
            if (!apply) {
                restore(entry, state);
            }
            if (isNew && !apply) {
                entry.remove();
            } else {
                entriesHost.appendChild(entry);
            }
            reindex(root);
            state = null;
            isNew = false;
            dialogBody.innerHTML = '';
            rebuild();
        }

        function edit(entry, created) {
            active = entry;
            isNew = created;
            state = snapshot(entry);
            if (dialogTitle) {
                dialogTitle.textContent = dialog.getAttribute(created ? 'data-title-add' : 'data-title-edit') || '';
            }
            dialogBody.appendChild(entry);
            initTomSelects(entry, placeNames, placeholder);
            openDialog(dialog);
            var first = entry.querySelector('input:not([type="hidden"]), select, textarea');
            if (first) {
                first.focus();
            }
        }

        root.querySelector('[data-collection-add]').addEventListener('click', function () {
            var holder = document.createElement('div');
            holder.innerHTML = (root.getAttribute('data-prototype') || '').replace(/__name__/g, root.getAttribute('data-index') || '0');
            var widget = holder.firstElementChild;
            if (!widget) {
                return;
            }
            var entry = document.createElement('div');
            entry.setAttribute('data-collection-entry', root.getAttribute('data-index') || '0');
            entry.appendChild(widget);
            entriesHost.appendChild(entry);
            reindex(root);
            edit(entry, true);
        });

        tbody.addEventListener('click', function (event) {
            var target = event.target instanceof Element ? event.target : null;
            if (!target) {
                return;
            }
            var editButton = target.closest('[data-row-edit]');
            var deleteButton = target.closest('[data-row-delete]');
            if (editButton) {
                var entry = entriesHost.querySelector('[data-collection-entry="' + editButton.getAttribute('data-row-edit') + '"]');
                if (entry) {
                    edit(entry, false);
                }
            } else if (deleteButton) {
                pendingRemoval = deleteButton.getAttribute('data-row-delete');
                if (removeDialog) {
                    openDialog(removeDialog);
                }
            }
        });

        if (removeDialog) {
            removeDialog.querySelector('[data-collection-remove-confirm]').addEventListener('click', function () {
                var entry = pendingRemoval === null ? null : entriesHost.querySelector('[data-collection-entry="' + pendingRemoval + '"]');
                pendingRemoval = null;
                if (entry) {
                    entry.remove();
                    reindex(root);
                    rebuild();
                }
                closeDialog(removeDialog);
            });
        }

        dialog.querySelector('[data-dialog-apply]').addEventListener('click', function () {
            // The entry is submitted with the page form later: report its invalid fields now, inside the dialog.
            var invalid = active ? active.querySelector(':invalid') : null;
            if (invalid && typeof invalid.reportValidity === 'function') {
                invalid.reportValidity();

                return;
            }
            finish(true);
            closeDialog(dialog);
        });
        dialog.querySelector('[data-dialog-cancel]').addEventListener('click', function () {
            finish(false);
            closeDialog(dialog);
        });
        // Esc / backdrop / header close button: discard the changes of the open entry.
        dialog.addEventListener('close', function () {
            finish(false);
        });

        rebuild();
    }

    function init() {
        document.querySelectorAll('[data-workflow-collection]').forEach(createCollection);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
