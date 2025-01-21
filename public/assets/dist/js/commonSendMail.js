$(document).ready(function() {
    //clear local storage exclude one key
    if (localStorage.getItem('old_storage') !== null && localStorage.getItem('old_storage') !== STORAGE_NAME_MAIL) {
        localStorage.removeItem(localStorage.getItem('old_storage'));
    }
    checkSelected();
    checkEditRow();
    countSelectCheckbox();
    /**
     * Submit form search
     */
    $('body').on('click', '.reload', function() {
        localStorage.setItem(STORAGE_NAME_MAIL, JSON.stringify([]));
        countSelectCheckbox();
    })

    /**
     * Submit form search
     */
    $('body').on('click', '.close', function() {
        $('.check-select').prop('checked', false);
    })

    /**
     * Checkbox all in list select
     */
    $('#check_select_all').on('change', function() {
        if ($(this).is(":checked")) {
            localStorage.setItem(STORAGE_NAME_MAIL, JSON.stringify(listIds));
            $('.check-select').prop('checked', true);
            $('.check-selected').prop('checked', true);
        } else {
            localStorage.setItem(STORAGE_NAME_MAIL, []);
            $('.check-select').prop('checked', false);
            $('.check-selected').prop('checked', false);
        }
        checkEditRow();
        countSelectCheckbox();
    });

    /**
     * Count selected
     */
    function countSelectCheckbox() {
        var selectedIds = localStorage.getItem(STORAGE_NAME_MAIL);
        selectedIds = !selectedIds ? '[]' : selectedIds;
        selectedIds = JSON.parse(selectedIds);
        selectedIds = selectedIds.filter((id) => { return listIds.includes(id) }); // check if set deleted
        var number = selectedIds.length;
        if (number > 0) {
            $('.disabled-button').prop('disabled', false);
            $('.box-btn-form').find('.selected').attr("disabled", false);
        } else {
            $('.disabled-button').prop('disabled', true);
            $('.box-btn-form').find('.selected').attr("disabled", true);
        }

        if (number == listIds.length && number != 0) {
            $('#check_select_all').prop('checked', true);
        } else {
            $('#check_select_all').prop('checked', false);
        }
    }

    /**
     * Check selected
     */
    function checkSelected() {
        var selectedIds = localStorage.getItem(STORAGE_NAME_MAIL);
        selectedIds = !selectedIds ? '[]' : selectedIds;
        selectedIds = JSON.parse(selectedIds);

        $.each(selectedIds, function(i, e) {
            $('#id_tr_' + e).find('.check-select').prop('checked', true);
            $('#id_tr_user_' + e).find('.check-selected').prop('checked', true);
        });
        localStorage.setItem('old_storage', STORAGE_NAME_MAIL);

        countSelectCheckbox();
    }

    /**
     * Checkbox in list select
     */
    $('body').on('change', '.check-select', function() {
        setLocalStorageSelected($(this));
        if ($(this).hasClass('is-edit')) {
            addRemoveDisabled($(this))
        }
    });

    /**
     * Checkbox in list select
     */
    $('body').on('change', '.check-selected', function() {
        setLocalStorageSelected($(this));
    });

    /**
     * Check edit
     */
    function checkEditRow() {
        $('.check-select').each(function(i, e) {
            addRemoveDisabled(e)
        });
    }

    /**
     * Add remove attribute disabled
     */
    function addRemoveDisabled(target) {
        if ($(target).is(":checked")) {
            $(target).closest('tr').find('input').not(".check-select").removeAttr('disabled');
            $(target).closest('tr').find('select').not(".check-select").removeAttr('disabled');
            $(target).closest('tr').find('#btn_status').not(".check-select").removeAttr('disabled');
        } else {
            $(target).closest('tr').find('input').not(".check-select").attr('disabled', 'disabled');
            $(target).closest('tr').find('select').not(".check-select").attr('disabled', 'disabled');
            $(target).closest('tr').find('#btn_status').not(".check-select").attr('disabled', 'disabled');
        }
    }

    /**
     * Set local storage selected
     */
    function setLocalStorageSelected(target) {
        var selectedIds = localStorage.getItem(STORAGE_NAME_MAIL);
        selectedIds = !selectedIds ? '[]' : selectedIds;
        selectedIds = JSON.parse(selectedIds);
        if (target.is(":checked") && selectedIds.indexOf(target.val()) === -1) {
            selectedIds.push(target.val());
        } else if (!target.is(":checked")) {
            selectedIds = selectedIds.filter(id => id !== target.val());
        }
        localStorage.setItem(STORAGE_NAME_MAIL, JSON.stringify(selectedIds));
        countSelectCheckbox();
    }

    /**
     * Change per page list
     */
    $("#per_page").on("change", function() {
        submitFormSearch();
    });
});
/**
 * Submit form search
 */
function submitFormSearch() {
    $('#search_form').submit();
    localStorage.removeItem(STORAGE_NAME_MAIL);
}

/**
 * send mail multiple
 */
function sendMailMulti() {
    var selectedIds = localStorage.getItem(STORAGE_NAME_MAIL);
    selectedIds = !selectedIds ? '[]' : selectedIds;
    selectedIds = JSON.parse(selectedIds);
    memoObj = {};
    var memo = $('.memo').each(function(i, obj) {
        memoObj[$(obj).data('id')] = $(obj).val();
    });
    confirmSendMail(selectedIds, memoObj);
}

/**
 * Confirm send Mail
 */
function confirmSendMail(ids, memoObj = '') {
    var url = SENDMAIL_URL;
    var content = "Do you really want to send mail this?";
    var data = {
        "url": url,
        "ids": ids,
        "content": content,
        'memo': memoObj
    };
    $.ajax({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        beforeSend: function() {
            $('#loading_wrapper').addClass('active');
        },
        complete: function(xhr) {
            $('#loading_wrapper').removeClass('active');
        },
        url: MODAL_CONFIRM_SEND_MAIL_URL,
        method: 'GET',
        data: data,
        // dataType: 'json',
        success: function(response) {
            $('body #sendMailModal').remove();
            $('body').append(response);
            $('body #sendMailModal').modal('show');
        },
        error: function() {
            // error mess
            toastr.error("An error has occurred");
        }
    });
}