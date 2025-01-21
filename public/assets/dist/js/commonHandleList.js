$(document).ready(function () {
    //clear local storage exclude one key
    if (localStorage.getItem('old_storage') !== null && localStorage.getItem('old_storage') !== STORAGE_NAME) {
        localStorage.removeItem(localStorage.getItem('old_storage'));
    }
    checkSelected();
    checkEditRow();
    countSelectCheckbox();
    /**
     * Submit form search
     */
    $('body').on('click', '.reload', function () {
        localStorage.setItem(STORAGE_NAME, JSON.stringify([]));
        countSelectCheckbox();
    })

    /**
     * Submit form search
     */
     $('body').on('click', '.close', function () {
        $('.check-select').prop('checked', false);
    })

    /**
     * Checkbox all in list select
     */
    $('#check_select_all').on('change', function () {
        if ($(this).is(":checked")) {
            localStorage.setItem(STORAGE_NAME, JSON.stringify(listIds));
            $('.check-select').prop('checked', true);
            $('.check-selected').prop('checked', true);
        } else {
            localStorage.setItem(STORAGE_NAME, []);
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
        var selectedIds = localStorage.getItem(STORAGE_NAME);
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
        var selectedIds = localStorage.getItem(STORAGE_NAME);
        selectedIds = !selectedIds ? '[]' : selectedIds;
        selectedIds = JSON.parse(selectedIds);

        $.each(selectedIds, function (i, e) {
            $('#id_tr_' + e).find('.check-select').prop('checked', true);
            $('#id_tr_user_' + e).find('.check-selected').prop('checked', true);
        });
        localStorage.setItem('old_storage', STORAGE_NAME);

        countSelectCheckbox();
    }

    /**
     * Checkbox in list select
     */
     $('body').on('change', '.check-select', function () {
        setLocalStorageSelected($(this));
        if($(this).hasClass('is-edit')) {
            addRemoveDisabled($(this))
        }
    });

    /**
     * Checkbox in list select
     */
    $('body').on('change', '.check-selected', function () {
        setLocalStorageSelected($(this));
    });

    /**
     * Check edit
     */
    function checkEditRow() {
        $('.check-select').each(function (i, e) {
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
            $(target).closest('tr').find('input').not(".check-select").attr('disabled','disabled');
            $(target).closest('tr').find('select').not(".check-select").attr('disabled','disabled');
            $(target).closest('tr').find('#btn_status').not(".check-select").attr('disabled','disabled');
        }
    }

    /**
     * Set local storage selected
     */
    function setLocalStorageSelected(target) {
        var selectedIds = localStorage.getItem(STORAGE_NAME);
        selectedIds = !selectedIds ? '[]' : selectedIds;
        selectedIds = JSON.parse(selectedIds);
        if (target.is(":checked") && selectedIds.indexOf(target.val()) === -1) {
            selectedIds.push(target.val());
        } else if (!target.is(":checked")) {
            selectedIds = selectedIds.filter(id => id !== target.val());
        }
        localStorage.setItem(STORAGE_NAME, JSON.stringify(selectedIds));
        countSelectCheckbox();
    }

    /**
     * Change per page list
     */
    $("#per_page").on("change", function () {
        submitFormSearch();
    });
});
/**
* Submit form search
*/
function submitFormSearch() {
    $('#search_form').submit();
    localStorage.removeItem(STORAGE_NAME);
}

/**
 * Delete multiple
 */
function deleteMulti() {
    var selectedIds = localStorage.getItem(STORAGE_NAME);
    selectedIds = !selectedIds ? '[]' : selectedIds;
    selectedIds = JSON.parse(selectedIds);
    memoObj = {};
    var memo = $('.memo').each(function(i, obj) {
        memoObj[$(obj).data('id')] = $(obj).val();
    });
    confirmDelete(selectedIds, memoObj);
}

/**
 * Confirm delete
 */
function confirmDelete(ids, memoObj = '') {
    var url = DELETE_URL;
    var content = "Do you really want to delete this?";
    var data = {
        "url": url,
        "ids": ids,
        "content": content,
        'memo' : memoObj
    };
    $.ajax({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        beforeSend: function () {
            $('#loading_wrapper').addClass('active');
        },
        complete: function (xhr) {
            $('#loading_wrapper').removeClass('active');
        },
        url: MODAL_CONFIRM_URL,
        method: 'GET',
        data: data,
        // dataType: 'json',
        success: function (response) {
            $('body #confirmModal').remove();
            $('body').append(response);
            $('body #confirmModal').modal('show');
        },
        error: function () {
            // error mess
            toastr.error("An error has occurred");
        }
    });
}

/**
 * Export csv file
 */
 function exportCSV() {
    var url = EXPORT_CSV;
    var selectedIds = localStorage.getItem(STORAGE_NAME);
    var data = {
        'data' : selectedIds,
    };
    $.ajax({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        },
        beforeSend: function () {
            $('#loading_wrapper').addClass('active');
        },
        complete: function (xhr) {
            $('#loading_wrapper').removeClass('active');
        },
        url: url,
        method: 'GET',
        data: data,
        // dataType: 'json',
        xhrFields:{
            responseType: 'blob'
        },
        success: function (response) {
            var link = document.createElement('a');
            link.href = window.URL.createObjectURL(response);
            link.download = `Client_list.csv`;
            link.click();
            link.remove();
        },
        error: function () {
            // error mess
            toastr.error("An error has occurred");
        }
    });
}

/**
 *Clear form search
 */
 function clearFormSearch(){
    $('#search_form')[0].reset();
    $('#search_form').find('input[type="text"]','textarea').val('');
    $('#search_form').find('input[type="number"]','textarea').val('');
    $('#search_form').find('input[type="checkbox"]').prop('checked', false);
    $('#search_form').find('select').not("#per_page").val($('select option:first').val());
    submitFormSearch();
}

