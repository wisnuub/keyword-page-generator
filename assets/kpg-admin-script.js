/**
 * Keyword Page Generator - Admin Interface Script
 * Handles dynamic keyword pairs, live page counter, mode toggle, AI toggle, form validation
 */

(function($) {
    'use strict';

    const KPG = {
        maxPairs: (typeof kpgData !== 'undefined' && kpgData.maxPairs) ? kpgData.maxPairs : 5,
        pageLimit: (typeof kpgData !== 'undefined' && kpgData.pageLimit) ? kpgData.pageLimit : 20,

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
                // Init state
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

            // Prevent double submission
            this.form.on('submit', function() {
                var $btn = $(this).find('button[type="submit"]:focus, button[type="submit"]:active');
                if ($btn.length) {
                    $btn.prop('disabled', true);
                    setTimeout(function() { $btn.prop('disabled', false); }, 3000);
                }
            });

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

                // Show/hide remove button
                if (i === 0) {
                    $(this).find('.kpg-remove-pair-btn').hide();
                } else {
                    $(this).find('.kpg-remove-pair-btn').show();
                }
            });
        },

        updatePairVisibility: function() {
            var count = this.pairsContainer.find('.kpg-pair-group').length;

            // Show/hide add button
            if (count >= this.maxPairs) {
                this.addPairBtn.hide();
            } else {
                this.addPairBtn.show();
            }

            // Show/hide generation mode (only when 2+ pairs)
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
                // Independent: sum of all replacement counts
                for (var i = 0; i < pairs.length; i++) {
                    total += pairs[i].values.length;
                }
            } else {
                // Matrix: product of all replacement counts
                total = 1;
                for (var j = 0; j < pairs.length; j++) {
                    total *= pairs[j].values.length;
                }
            }

            this.pageCountEl.text(total);

            // Style based on limit
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
            // ARIA live region for feedback
            if ($('#kpg-form-feedback').length === 0) {
                $('body').append('<div id="kpg-form-feedback" class="sr-only" aria-live="polite" aria-atomic="true"></div>');
            }

            // Mark form sections
            this.form.find('.kpg-form-section').attr('role', 'region').each(function(index) {
                var $title = $(this).find('.kpg-section-title');
                if ($title.length) {
                    var titleId = 'section-title-' + index;
                    $title.attr('id', titleId);
                    $(this).attr('aria-labelledby', titleId);
                }
            });

            // Keyboard: Ctrl+Enter submits
            this.form.on('keydown', function(e) {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    $(this).submit();
                }
            });
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
                // Select first visible if none selected
                if (!$model.find('option:selected:visible').length && firstVisible) {
                    firstVisible.prop('selected', true);
                }
            }
            $provider.on('change', filterModels);
            filterModels();
        }
    });

})(jQuery);
