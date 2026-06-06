/**
 * WooCommerce Product Extra Options - Frontend
 * Handles file upload AJAX, date picker initialization, and price display.
 */
(function($) {
    'use strict';

    // ---------------------------------------------------------------
    // Date Picker
    // ---------------------------------------------------------------
    function initDatePickers() {
        $('.wcpa-datepicker').each(function() {
            var $input = $(this);
            var dateFormat = $input.data('date-format') || 'yy-mm-dd';
            // Convert PHP date format to jQuery UI format
            var jqFormat = dateFormat
                .replace(/Y/g, 'yy')
                .replace(/m/g, 'mm')
                .replace(/d/g, 'dd');
            
            $input.datepicker({
                dateFormat: jqFormat,
                changeMonth: true,
                changeYear: true,
                yearRange: '-100:+10'
            });
        });
    }

    // ---------------------------------------------------------------
    // File Upload via AJAX
    // ---------------------------------------------------------------
    function handleFileUpload(input) {
        var $input = $(input);
        var $wrapper = $input.closest('.wcpa-file-input-wrapper');
        var $fileName = $wrapper.find('.wcpa-file-name');
        var $fileUrl = $wrapper.find('.wcpa-file-url');
        var $fileNameHidden = $wrapper.find('.wcpa-file-name-hidden');
        var $progress = $wrapper.find('.wcpa-upload-progress');
        var $progressBar = $wrapper.find('.wcpa-progress-bar');
        var $error = $wrapper.find('.wcpa-upload-error');

        var file = input.files[0];
        if (!file) {
            $fileName.text('');
            $fileUrl.val('');
            $fileNameHidden.val('');
            return;
        }

        // Check allowed types
        var allowedTypes = $input.data('allowed-types') || 'jpg,jpeg,png,gif,pdf,doc,docx';
        var ext = file.name.split('.').pop().toLowerCase();
        var allowed = allowedTypes.split(',').map(function(t) { return t.trim().toLowerCase(); });

        if (allowed.indexOf(ext) === -1) {
            $error.text('File type "' + ext + '" is not allowed. Allowed: ' + allowedTypes);
            $fileName.text('');
            $fileUrl.val('');
            $fileNameHidden.val('');
            $progress.hide();
            return;
        }

        // Check max size
        var maxSize = ($input.data('max-size') || 2) * 1024 * 1024;
        if (file.size > maxSize) {
            $error.text('File exceeds maximum size of ' + $input.data('max-size') + ' MB.');
            $fileName.text('');
            $fileUrl.val('');
            $fileNameHidden.val('');
            $progress.hide();
            return;
        }

        $error.text('');
        $fileName.text('Uploading...');
        $progress.show();
        $progressBar.css('width', '0%');

        var formData = new FormData();
        formData.append('action', 'wcpa_upload_file');
        formData.append('nonce', wcpa_params.nonce);
        formData.append('wcpa_file', file);

        $.ajax({
            url: wcpa_params.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percent = Math.round((e.loaded / e.total) * 100);
                        $progressBar.css('width', percent + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                $progress.hide();
                if (response.success) {
                    $fileName.text(response.data.filename);
                    $fileUrl.val(response.data.url);
                    $fileNameHidden.val(response.data.filename);
                    $error.text('');
                } else {
                    $error.text(response.data.message || 'Upload failed.');
                    $fileName.text('');
                    $fileUrl.val('');
                    $fileNameHidden.val('');
                }
            },
            error: function() {
                $progress.hide();
                $error.text('Upload failed. Please try again.');
                $fileName.text('');
                $fileUrl.val('');
                $fileNameHidden.val('');
            }
        });
    }

    // ---------------------------------------------------------------
    // Initialize on page load
    // ---------------------------------------------------------------
    $(document).ready(function() {
        initDatePickers();

        // File upload change handler
        $(document).on('change', '.wcpa-input-file', function() {
            handleFileUpload(this);
        });
    });

    // Re-init date pickers after AJAX (compatibility with some themes)
    $(document).on('found_variation updated_checkout', function() {
        initDatePickers();
    });

})(jQuery);
