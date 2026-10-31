jQuery(document).ready(function($) {
    // Confirm destructive links (e.g. Delete event)
    $('.idloom-confirm').on('click', function(e) {
        if (!window.confirm($(this).data('confirm'))) {
            e.preventDefault();
        }
    });

    // Select the shortcode on click so it's easy to copy
    $('.idloom-copy').on('focus click', function() {
        this.select();
    });

    // Columns: add, remove, reorder
    let columnIndex = Date.now();

    $('#idloom-add-column').on('click', function() {
        const html = $('#idloom-column-template').html().replace(/__i__/g, 'n' + (columnIndex++));
        $('#idloom-columns-body').append(html);
    });

    $('#idloom-columns-body')
        .on('click', '.idloom-remove-column', function() {
            $(this).closest('tr').remove();
        })
        .on('click', '.idloom-move-up', function() {
            const $row = $(this).closest('tr');
            $row.prev().before($row);
        })
        .on('click', '.idloom-move-down', function() {
            const $row = $(this).closest('tr');
            $row.next().after($row);
        });

    // Show the exception field only when it's used
    function toggleException() {
        $('#idloom-exception-row').toggle($('#idloom-payment').val() === 'paid_or_exception');
    }
    $('#idloom-payment').on('change', toggleException);
    toggleException();

    // Warn when there's no opt-in field
    function toggleOptinWarning() {
        $('#idloom-optin-warning').prop('hidden', $.trim($('#idloom-optin').val()) !== '');
    }
    $('#idloom-optin').on('change', toggleOptinWarning);
    if ($('#idloom-optin').length) {
        toggleOptinWarning();
    }

    // A column's label starts as its field's label until edited by hand
    $('#idloom-columns-body').on('change', '.idloom-field-select', function() {
        const $label = $(this).closest('tr').find('input[name$="[label]"]');
        const fieldLabel = $(this).val() ? $(this).find('option:selected').text() : '';

        if ($label.val() === '' || $label.val() === $label.data('auto')) {
            $label.val(fieldLabel).data('auto', fieldLabel);
        }
    });

    // Replace the options of every field dropdown (including the one in the
    // new-column template), keeping each dropdown's current choice.
    function setFieldOptions(fields) {
        const template = document.getElementById('idloom-column-template');
        const selects = $('.idloom-field-select').get()
            .concat(template ? Array.from(template.content.querySelectorAll('.idloom-field-select')) : []);

        selects.forEach(function(select) {
            const current = select.value;
            const $select = $(select).empty()
                .append($('<option>').val('').text(select.dataset.empty));

            fields.forEach(function(field) {
                $select.append($('<option>').val(field.name).text(field.label));
            });

            if (current && !fields.some(function(field) { return field.name === current; })) {
                $select.append($('<option>').val(current).text(current + ' (not on the form)'));
            }

            select.value = current;
        });
    }

    // Load this event's registration form fields and labels from Idloom
    $('#idloom-load-fields').on('click', function() {
        const $button = $(this);
        const $result = $('#idloom-fields-result');

        $button.prop('disabled', true);
        $result.text('Loading…');

        $.post(IdloomAdminData.ajaxUrl, {
            action: 'idloom_load_fields',
            nonce: IdloomAdminData.nonce,
            event_uid: $('#idloom-event-uid').val()
        }).done(function(response) {
            if (!response.success) {
                $result.text(response.data);
                return;
            }

            setFieldOptions(response.data);
            $result.text(response.data.length + ' fields loaded from the registration form.');
        }).fail(function() {
            $result.text('Request failed.');
        }).always(function() {
            $button.prop('disabled', false);
        });
    });

    // Test a saved event
    $('#idloom-test-event').on('click', function() {
        const $button = $(this);
        const $result = $('#idloom-test-result');

        $button.prop('disabled', true);
        $result.text('Loading registrations from Idloom…');

        $.post(IdloomAdminData.ajaxUrl, {
            action: 'idloom_test_event',
            nonce: IdloomAdminData.nonce,
            event: $button.data('event')
        }).done(function(response) {
            if (!response.success) {
                $result.text(response.data);
                return;
            }

            const c = response.data;
            const $ul = $('<ul>');
            const rows = [
                [c.not_completed, 'left out: registration not completed'],
                [c.not_paid, 'left out: not paid'],
                [c.not_opted_in, 'left out: did not opt in'],
                [c.invalid, 'left out: unreadable record']
            ];
            rows.forEach(function(row) {
                if (row[0] > 0) {
                    $ul.append($('<li>').text(row[0] + ' ' + row[1]));
                }
            });

            $result.empty()
                .append($('<p>').append($('<strong>').text(c.shown + ' of ' + c.total + ' registrations will be listed.')))
                .append($ul);
        }).fail(function() {
            $result.text('Request failed.');
        }).always(function() {
            $button.prop('disabled', false);
        });
    });
});
