/**
 * Keyword Page Generator - Admin Interface Script
 * Handles dynamic keyword pairs, live page counter, mode toggle, AI toggle,
 * form validation, AJAX batch processing, CSV import, and scheduled generation.
 */

(function($) {
    'use strict';

    var kpg = (typeof kpgData !== 'undefined') ? kpgData : {};

    var KPG = {
        maxPairs: kpg.maxPairs || 5,
        pageLimit: kpg.pageLimit || 20,

        init: function() {
            this.form = $('#kpg-main-form');
            if (!this.form.length) return;

            this.pairsContainer = $('#kpg-pairs-container');
            this.addPairBtn = $('#kpg-add-pair-btn');
            this.modeSection = $('#kpg-mode-section');
            this.pageCountEl = $('#kpg-page-count');
            this.pageCounter = $('#kpg-page-counter');
            this.aiToggle = $('#kpg-ai-toggle');
            this.aiOptions = $('#kpg-ai-options');
            this.previewToggle = $('#kpg-preview-toggle');
            this.previewBtn = $('#kpg-preview-btn');
            this.previewWarning = $('#kpg-preview-warning');

            this.bindEvents();
            this.updatePairVisibility();
            this.updatePageCount();
            this.setupValidation();
            this.enhanceAccessibility();
            this.csvImport.init();
            this.batch.init();
            this.schedule.init();
        },

        bindEvents: function() {
            var self = this;

            // Add keyword pair
            this.addPairBtn.on('click', function() {
                self.addPair();
            });

            // Remove keyword pair (delegated)
            this.pairsContainer.on('click', '.kpg-remove-pair-btn', function() {
                $(this).closest('.kpg-pair-group').slideUp(200, function() {
                    $(this).remove();
                    self.reindexPairs();
                    self.updatePairVisibility();
                    self.updatePageCount();
                });
            });

            // Update page count on any input change
            this.pairsContainer.on('input change', '.kpg-pair-replace, .kpg-pair-find', function() {
                self.updatePageCount();
            });

            // Mode change updates page count
            $('input[name="generation_mode"]').on('change', function() {
                self.updatePageCount();
            });

            // AI toggle
            if (this.aiToggle.length) {
                this.aiToggle.on('change', function() {
                    self.aiOptions.toggle($(this).is(':checked'));
                });
                if (this.aiToggle.is(':checked')) {
                    this.aiOptions.show();
                }
            }

            // Preview toggle
            if (this.previewToggle.length) {
                this.previewToggle.on('change', function() {
                    var checked = $(this).is(':checked');
                    self.previewBtn.toggle(checked);
                    self.previewWarning.toggle(checked);
                });
            }

            // Field validation on blur
            this.form.on('blur', '.kpg-input, .kpg-textarea, .kpg-select', function() {
                self.validateField($(this));
            });
        },

        addPair: function() {
            var count = this.pairsContainer.find('.kpg-pair-group').length;
            if (count >= this.maxPairs) {
                alert('Maximum ' + this.maxPairs + ' keyword pairs allowed.');
                return;
            }

            var index = count;
            var html = '<div class="kpg-pair-group" data-pair-index="' + index + '" style="display:none;">' +
                '<div class="kpg-pair-header">' +
                    '<span class="kpg-pair-label">Keyword Pair ' + (index + 1) + '</span>' +
                    '<button type="button" class="kpg-remove-pair-btn" aria-label="Remove this keyword pair">&times;</button>' +
                '</div>' +
                '<div class="kpg-pair-fields">' +
                    '<div class="kpg-form-field">' +
                        '<label class="kpg-label">Find keyword</label>' +
                        '<input type="text" name="pairs[' + index + '][find]" class="kpg-input kpg-pair-find" placeholder="e.g. Melbourne CBD" required>' +
                    '</div>' +
                    '<div class="kpg-form-field">' +
                        '<label class="kpg-label">Replace with</label>' +
                        '<textarea name="pairs[' + index + '][replace]" class="kpg-input kpg-textarea kpg-pair-replace" rows="3" placeholder="Comma-separated values, e.g. Sydney, Brisbane, Perth" required></textarea>' +
                        '<label class="kpg-csv-pair-import kpg-btn kpg-btn-outline kpg-btn-xs">Import CSV <input type="file" accept=".csv,.txt" class="kpg-csv-pair-file" style="display:none;"></label>' +
                    '</div>' +
                '</div>' +
            '</div>';

            this.pairsContainer.append(html);
            this.pairsContainer.find('.kpg-pair-group').last().slideDown(200);
            this.updatePairVisibility();
            this.updatePageCount();
        },

        reindexPairs: function() {
            this.pairsContainer.find('.kpg-pair-group').each(function(i) {
                $(this).attr('data-pair-index', i);
                $(this).find('.kpg-pair-label').text('Keyword Pair ' + (i + 1));
                $(this).find('.kpg-pair-find').attr('name', 'pairs[' + i + '][find]');
                $(this).find('.kpg-pair-replace').attr('name', 'pairs[' + i + '][replace]');

                if (i === 0) {
                    $(this).find('.kpg-remove-pair-btn').hide();
                } else {
                    $(this).find('.kpg-remove-pair-btn').show();
                }
            });
        },

        updatePairVisibility: function() {
            var count = this.pairsContainer.find('.kpg-pair-group').length;

            if (count >= this.maxPairs) {
                this.addPairBtn.hide();
            } else {
                this.addPairBtn.show();
            }

            if (count >= 2) {
                this.modeSection.slideDown(200);
            } else {
                this.modeSection.slideUp(200);
            }
        },

        updatePageCount: function() {
            var pairs = this.getPairData();
            if (pairs.length === 0) {
                this.pageCountEl.text('0');
                this.pageCounter.removeClass('kpg-counter-warning kpg-counter-error');
                return;
            }

            var mode = $('input[name="generation_mode"]:checked').val() || 'matrix';
            var total = 0;

            if (pairs.length === 1 || mode === 'independent') {
                for (var i = 0; i < pairs.length; i++) {
                    total += pairs[i].values.length;
                }
            } else {
                total = 1;
                for (var j = 0; j < pairs.length; j++) {
                    total *= pairs[j].values.length;
                }
            }

            this.pageCountEl.text(total);

            this.pageCounter.removeClass('kpg-counter-warning kpg-counter-error');
            if (total > this.pageLimit) {
                this.pageCounter.addClass('kpg-counter-error');
            } else if (total > this.pageLimit * 0.8) {
                this.pageCounter.addClass('kpg-counter-warning');
            }
        },

        getPairData: function() {
            var pairs = [];
            this.pairsContainer.find('.kpg-pair-group').each(function() {
                var find = $(this).find('.kpg-pair-find').val().trim();
                var replace = $(this).find('.kpg-pair-replace').val().trim();
                if (find && replace) {
                    var values = replace.split(',').map(function(v) { return v.trim(); }).filter(function(v) { return v.length > 0; });
                    if (values.length > 0) {
                        pairs.push({ find: find, values: values });
                    }
                }
            });
            return pairs;
        },

        validateField: function($field) {
            var value = $field.val() ? $field.val().trim() : '';
            var isRequired = $field.prop('required');

            if (isRequired && !value) {
                this.showFieldError($field, 'This field is required');
                $field.attr('aria-invalid', 'true');
            } else {
                this.hideFieldError($field);
                $field.attr('aria-invalid', 'false');
            }
        },

        showFieldError: function($field, message) {
            var $parent = $field.closest('.kpg-form-field');
            if ($parent.find('.kpg-field-error').length === 0) {
                $parent.append('<p class="kpg-field-error" role="alert">' + message + '</p>');
            }
            $field.addClass('kpg-field-invalid');
        },

        hideFieldError: function($field) {
            var $parent = $field.closest('.kpg-form-field');
            $parent.find('.kpg-field-error').remove();
            $field.removeClass('kpg-field-invalid');
        },

        setupValidation: function() {
            var self = this;
            this.form.on('submit', function(e) {
                var $btn = $(document.activeElement);

                // Intercept "Generate All Pages" for AJAX batch
                if ($btn.attr('id') === 'kpg-generate-btn') {
                    e.preventDefault();
                    self.batch.start();
                    return;
                }

                // Regular validation for preview
                var isValid = true;
                $(this).find('[required]').each(function() {
                    var val = $(this).val() ? $(this).val().trim() : '';
                    if (!val || ($(this).is('select') && val === '')) {
                        self.validateField($(this));
                        isValid = false;
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    $(this).find('[aria-invalid="true"]:first').focus();
                }
            });
        },

        enhanceAccessibility: function() {
            if ($('#kpg-form-feedback').length === 0) {
                $('body').append('<div id="kpg-form-feedback" class="sr-only" aria-live="polite" aria-atomic="true"></div>');
            }

            this.form.find('.kpg-form-section').attr('role', 'region').each(function(index) {
                var $title = $(this).find('.kpg-section-title');
                if ($title.length) {
                    var titleId = 'section-title-' + index;
                    $title.attr('id', titleId);
                    $(this).attr('aria-labelledby', titleId);
                }
            });

            this.form.on('keydown', function(e) {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    $(this).submit();
                }
            });
        },

        // ============================================================
        // CSV Import
        // ============================================================

        csvImport: {
            init: function() {
                var self = this;

                // Bulk CSV import
                $('#kpg-csv-bulk-import').on('change', function() {
                    self.handleBulkImport(this);
                });

                // Per-pair CSV import (delegated)
                KPG.pairsContainer.on('click', '.kpg-csv-pair-import', function() {
                    $(this).find('.kpg-csv-pair-file').trigger('click');
                });

                KPG.pairsContainer.on('change', '.kpg-csv-pair-file', function() {
                    var pairIndex = $(this).closest('.kpg-pair-group').data('pair-index');
                    self.handleSingleImport(this, pairIndex);
                });

                // Add per-pair import buttons to existing pair groups
                KPG.pairsContainer.find('.kpg-pair-group').each(function() {
                    var $replaceField = $(this).find('.kpg-pair-replace').closest('.kpg-form-field');
                    if ($replaceField.find('.kpg-csv-pair-import').length === 0) {
                        $replaceField.append('<label class="kpg-csv-pair-import kpg-btn kpg-btn-outline kpg-btn-xs">Import CSV <input type="file" accept=".csv,.txt" class="kpg-csv-pair-file" style="display:none;"></label>');
                    }
                });
            },

            parseCSV: function(text) {
                var lines = text.split(/\r?\n/).filter(function(l) { return l.trim().length > 0; });
                var result = [];
                for (var i = 0; i < lines.length; i++) {
                    result.push(this.parseLine(lines[i]));
                }
                return result;
            },

            parseLine: function(line) {
                var result = [];
                var current = '';
                var inQuotes = false;
                for (var i = 0; i < line.length; i++) {
                    var ch = line[i];
                    if (ch === '"') {
                        inQuotes = !inQuotes;
                    } else if (ch === ',' && !inQuotes) {
                        result.push(current.trim());
                        current = '';
                    } else {
                        current += ch;
                    }
                }
                result.push(current.trim());
                return result;
            },

            handleSingleImport: function(fileInput, pairIndex) {
                var file = fileInput.files[0];
                if (!file) return;
                var self = this;
                var reader = new FileReader();
                reader.onload = function(e) {
                    var data = self.parseCSV(e.target.result);
                    var values = [];
                    for (var i = 0; i < data.length; i++) {
                        if (data[i][0]) values.push(data[i][0]);
                    }
                    var $textarea = KPG.pairsContainer.find('.kpg-pair-group[data-pair-index="' + pairIndex + '"]').find('.kpg-pair-replace');
                    $textarea.val(values.join(', ')).trigger('input');
                };
                reader.readAsText(file);
                // Reset so same file can be re-selected
                fileInput.value = '';
            },

            handleBulkImport: function(fileInput) {
                var file = fileInput.files[0];
                if (!file) return;
                var self = this;
                var reader = new FileReader();
                reader.onload = function(e) {
                    var data = self.parseCSV(e.target.result);
                    if (data.length < 2) {
                        alert('CSV must have a header row and at least one data row.');
                        return;
                    }

                    var headers = data[0];
                    var numPairs = Math.min(headers.length, KPG.maxPairs);

                    // Ensure we have enough pair groups
                    var currentPairs = KPG.pairsContainer.find('.kpg-pair-group').length;
                    while (currentPairs < numPairs) {
                        KPG.addPair();
                        currentPairs++;
                    }

                    // Populate each pair
                    for (var col = 0; col < numPairs; col++) {
                        var $group = KPG.pairsContainer.find('.kpg-pair-group').eq(col);
                        $group.find('.kpg-pair-find').val(headers[col]).trigger('input');

                        var values = [];
                        for (var row = 1; row < data.length; row++) {
                            if (data[row][col] && data[row][col].trim()) {
                                values.push(data[row][col].trim());
                            }
                        }
                        $group.find('.kpg-pair-replace').val(values.join(', ')).trigger('input');
                    }

                    KPG.updatePairVisibility();
                    KPG.updatePageCount();
                };
                reader.readAsText(file);
                fileInput.value = '';
            }
        },

        // ============================================================
        // AJAX Batch Processing
        // ============================================================

        batch: {
            jobId: null,
            total: 0,
            current: 0,
            cancelled: false,
            results: [],

            init: function() {
                var self = this;
                $('#kpg-progress-cancel').on('click', function() {
                    self.cancel();
                });
            },

            start: function() {
                var self = this;
                this.jobId = null;
                this.total = 0;
                this.current = 0;
                this.cancelled = false;
                this.results = [];

                // Serialize BEFORE disabling — disabled fields are excluded by jQuery serialize()
                var formData = KPG.form.serialize();

                // Show progress UI
                $('#kpg-progress-container').slideDown(200);
                $('#kpg-batch-summary').hide().empty();
                $('#kpg-progress-fill').css('width', '0%');
                $('#kpg-progress-text').text('Starting batch...');
                $('#kpg-progress-log').empty();
                KPG.form.find('button, input, textarea, select').prop('disabled', true);
                $('#kpg-progress-cancel').prop('disabled', false);

                $.ajax({
                    url: kpg.ajaxUrl,
                    type: 'POST',
                    data: formData + '&action=kpg_start_batch&nonce=' + kpg.nonce,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            self.jobId = response.data.job_id;
                            self.total = response.data.total;
                            $('#kpg-progress-text').text('Processing page 1 of ' + self.total + '...');
                            self.processNext();
                        } else {
                            self.showError(response.data || 'Failed to start batch.');
                        }
                    },
                    error: function() {
                        self.showError('Network error. Please try again.');
                    }
                });
            },

            processNext: function() {
                var self = this;

                if (this.cancelled || this.current >= this.total) {
                    this.complete();
                    return;
                }

                $.ajax({
                    url: kpg.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'kpg_process_step',
                        nonce: kpg.nonce,
                        job_id: this.jobId,
                        step: this.current
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            self.current++;
                            self.results.push(response.data.result);
                            self.updateProgress(response.data.result);
                            self.processNext();
                        } else {
                            if (self.cancelled) {
                                self.complete();
                            } else {
                                self.showError(response.data || 'Error processing step.');
                            }
                        }
                    },
                    error: function() {
                        self.showError('Network error at step ' + (self.current + 1) + '.');
                    }
                });
            },

            updateProgress: function(result) {
                var pct = Math.round(this.current / this.total * 100);
                $('#kpg-progress-fill').css('width', pct + '%');
                $('#kpg-progress-text').text('Processing page ' + Math.min(this.current + 1, this.total) + ' of ' + this.total + '...');

                var icon = result.status === 'created' ? '&#9989;' : '&#10060;';
                var warning = result.warning ? ' <span class="kpg-log-warning">(' + result.warning + ')</span>' : '';
                $('#kpg-progress-log').append('<div class="kpg-log-entry kpg-log-' + result.status + '">' + icon + ' ' + result.title + warning + '</div>');

                // Auto-scroll log
                var log = document.getElementById('kpg-progress-log');
                if (log) log.scrollTop = log.scrollHeight;
            },

            cancel: function() {
                this.cancelled = true;
                $('#kpg-progress-text').text('Cancelling...');
                $('#kpg-progress-cancel').prop('disabled', true);

                $.ajax({
                    url: kpg.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'kpg_cancel_batch',
                        nonce: kpg.nonce,
                        job_id: this.jobId
                    },
                    dataType: 'json'
                });
            },

            complete: function() {
                var created = 0, skipped = 0, warnings = 0;
                for (var i = 0; i < this.results.length; i++) {
                    if (this.results[i].status === 'created') created++;
                    else skipped++;
                    if (this.results[i].warning) warnings++;
                }

                $('#kpg-progress-fill').css('width', '100%');
                $('#kpg-progress-text').text(this.cancelled ? 'Cancelled' : 'Complete!');
                $('#kpg-progress-cancel').hide();

                var summary = '<div class="kpg-batch-summary-inner">';
                summary += '<h3>' + (this.cancelled ? 'Batch Cancelled' : 'Batch Complete') + '</h3>';
                summary += '<p>' + created + ' page(s) created';
                if (skipped > 0) summary += ', ' + skipped + ' skipped';
                if (warnings > 0) summary += ', ' + warnings + ' with AI warnings';
                summary += '.</p>';
                if (this.cancelled) summary += '<p>Stopped at page ' + this.current + ' of ' + this.total + '.</p>';
                summary += '</div>';

                $('#kpg-batch-summary').html(summary).slideDown(200);

                // Re-enable form
                KPG.form.find('button, input, textarea, select').prop('disabled', false);
                $('#kpg-progress-cancel').show().prop('disabled', false);
            },

            showError: function(message) {
                $('#kpg-progress-text').text('Error: ' + message);
                $('#kpg-progress-fill').addClass('kpg-progress-error');
                KPG.form.find('button, input, textarea, select').prop('disabled', false);
                $('#kpg-progress-cancel').hide();
            }
        },

        // ============================================================
        // Scheduled Generation
        // ============================================================

        schedule: {
            init: function() {
                var self = this;

                $('#kpg-schedule-btn').on('click', function() {
                    $('#kpg-schedule-panel').slideToggle(200);
                });

                $('#kpg-schedule-mode').on('change', function() {
                    $('#kpg-schedule-datetime-field').toggle($(this).val() === 'timed');
                });

                $('#kpg-schedule-confirm').on('click', function() {
                    self.submit();
                });

                // Clear job button
                $('#kpg-clear-job-btn').on('click', function() {
                    self.clearJob();
                });
            },

            submit: function() {
                var mode = $('#kpg-schedule-mode').val();
                var datetime = $('#kpg-schedule-datetime').val();

                if (mode === 'timed' && !datetime) {
                    alert('Please select a date and time.');
                    return;
                }

                var $btn = $('#kpg-schedule-confirm');
                $btn.prop('disabled', true).text('Scheduling...');

                $.ajax({
                    url: kpg.ajaxUrl,
                    type: 'POST',
                    data: KPG.form.serialize() + '&action=kpg_schedule_job&nonce=' + kpg.nonce + '&schedule_mode=' + mode + '&schedule_time=' + encodeURIComponent(datetime),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Failed to schedule.'));
                            $btn.prop('disabled', false).text('Confirm Schedule');
                        }
                    },
                    error: function() {
                        alert('Network error. Please try again.');
                        $btn.prop('disabled', false).text('Confirm Schedule');
                    }
                });
            },

            clearJob: function() {
                $.ajax({
                    url: kpg.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'kpg_clear_job',
                        nonce: kpg.nonce
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Failed to clear job.'));
                        }
                    }
                });
            }
        }
    };

    // Initialize
    $(document).ready(function() {
        KPG.init();

        // Preview card animation
        $('.kpg-preview-card').fadeIn(500);

        // Model dropdown filtering based on provider (AI settings page)
        var $provider = $('#kpg_provider');
        var $model = $('#kpg_model');
        if ($provider.length && $model.length) {
            function filterModels() {
                var selected = $provider.val();
                var firstVisible = null;
                $model.find('option').each(function() {
                    var optProvider = $(this).data('provider');
                    if (optProvider && optProvider !== selected) {
                        $(this).hide();
                        if ($(this).is(':selected')) {
                            $(this).prop('selected', false);
                        }
                    } else {
                        $(this).show();
                        if (!firstVisible) firstVisible = $(this);
                    }
                });
                if (!$model.find('option:selected:visible').length && firstVisible) {
                    firstVisible.prop('selected', true);
                }
            }
            $provider.on('change', filterModels);
            filterModels();
        }
    });

})(jQuery);
