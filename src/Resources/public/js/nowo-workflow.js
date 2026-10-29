/*
 * NowoWorkflowBundle admin UI behaviour (REQ-UI-003, REQ-UX-001): no framework JS, no inline handlers, no confirm(),
 * no innerHTML / document.write / eval and no global API of its own (data-* attributes drive everything).
 *
 * - Dialogs: the UiKit `dialog` partial and js/nowo-ui-dialog.js open / close them (data-nowo-ui-dialog-open,
 *   data-nowo-ui-dialog-close, Esc, backdrop click). Place → transition links in the flow diagram swap dialogs
 *   through the UiKit 2.2 data-nowo-ui-dialog-replace opener (one dialog open at a time, focus handed over).
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

            select.replaceChildren();
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

    // codeClass: the UiKit `code` slot classes (data-code-class on the collection root).
    function codeNode(text, codeClass) {
        var code = document.createElement('code');
        if (codeClass) {
            code.className = codeClass;
        }
        code.textContent = text;

        return code;
    }

    // Field values are user input: they only ever reach the DOM as text nodes.
    function fillCell(cell, value, format, codeClass) {
        if (Array.isArray(value)) {
            if (value.length === 0) {
                cell.textContent = '—';

                return;
            }
            value.forEach(function (item, index) {
                if (index > 0) {
                    cell.appendChild(document.createTextNode(', '));
                }
                cell.appendChild(codeNode(String(item), codeClass));
            });

            return;
        }
        if (value === '') {
            cell.textContent = '—';
        } else if (format === 'code') {
            cell.appendChild(codeNode(value, codeClass));
        } else {
            cell.textContent = value;
        }
    }

    // New entry from the server-rendered <template data-collection-prototype>: clone it and number the
    // __name__ placeholders in attributes (name, id, for…) and text — no HTML string is ever parsed.
    function prototypeEntry(prototype, index) {
        var fragment = prototype.content.cloneNode(true);
        fragment.querySelectorAll('*').forEach(function (element) {
            Array.prototype.forEach.call(element.attributes, function (attribute) {
                if (attribute.value.indexOf('__name__') !== -1) {
                    attribute.value = attribute.value.split('__name__').join(index);
                }
            });
        });
        var walker = document.createTreeWalker(fragment, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            if (walker.currentNode.nodeValue.indexOf('__name__') !== -1) {
                walker.currentNode.nodeValue = walker.currentNode.nodeValue.split('__name__label__').join(index).split('__name__').join(index);
            }
        }

        return fragment.firstElementChild ? fragment : null;
    }

    function createCollection(root) {
        var columns = JSON.parse(root.getAttribute('data-columns') || '[]');
        var placeNames = JSON.parse(root.getAttribute('data-place-names') || '[]');
        var placeholder = root.getAttribute('data-place-placeholder') || '';
        var dialog = document.getElementById(root.getAttribute('data-dialog-id') || '');
        var removeDialog = document.getElementById(root.getAttribute('data-remove-dialog-id') || '');
        var rowTemplate = root.querySelector('template[data-collection-row]');
        var prototype = root.querySelector('template[data-collection-prototype]');
        var tbody = root.querySelector('[data-collection-table]');
        var emptyState = root.querySelector('[data-collection-empty]');
        var entriesHost = root.querySelector('[data-collection-entries]');
        if (!dialog || !rowTemplate || !tbody || !entriesHost || root.hasAttribute('data-collection-ready')) {
            return;
        }
        root.setAttribute('data-collection-ready', '');

        var dialogBody = dialog.querySelector('[data-dialog-body]');
        var dialogTitle = document.getElementById(dialog.id + '-title');
        var active = null;
        var state = null;
        var isNew = false;
        var pendingRemoval = null;

        function rebuild() {
            var entries = root.querySelectorAll('[data-collection-entries] [data-collection-entry]');
            tbody.replaceChildren();
            entries.forEach(function (entry, index) {
                var row = rowTemplate.content.firstElementChild.cloneNode(true);
                var cellTemplate = row.querySelector('[data-collection-cell]');
                var actionsCell = cellTemplate.nextElementSibling;
                cellTemplate.remove();
                columns.forEach(function (column) {
                    var cell = cellTemplate.cloneNode(false);
                    cell.removeAttribute('data-collection-cell');
                    cell.setAttribute('data-label', column.label);
                    fillCell(cell, fieldValue(entry, column.field), column.format, root.getAttribute('data-code-class') || '');
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
            dialogBody.replaceChildren();
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
            var index = root.getAttribute('data-index') || '0';
            var widget = prototype ? prototypeEntry(prototype, index) : null;
            if (!widget) {
                return;
            }
            var entry = document.createElement('div');
            entry.setAttribute('data-collection-entry', index);
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
