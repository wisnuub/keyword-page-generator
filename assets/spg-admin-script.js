/**
 * Suburb Page Generator - Admin Interface Script
 * Handles form validation, accessibility, and user interactions
 */

(function($) {
    'use strict';

    const SPG = {
        init: function() {
            this.form = $('.spg-form');
            this.bindEvents();
            this.enhanceAccessibility();
            this.setupValidation();
            this.setupCharacterCount();
        },

        /**
         * Bind events to form elements
         */
        bindEvents: function() {
            const self = this;

            // Prevent double submission
            this.form.on('submit', function(e) {
                const $button = $(this).find('button[type="submit"]:focus, button[type="submit"]:active');
                if ($button.length) {
                    $button.prop('disabled', true);
                    setTimeout(function() {
                        $button.prop('disabled', false);
                    }, 2000);
                }
            });

            // Add input validation feedback
            this.form.find('.spg-input, .spg-textarea, .spg-select').on('change blur', function() {
                self.validateField($(this));
            });

            // Add character count to textarea
            this.form.find('.spg-textarea').on('input', function() {
                self.updateCharacterCount($(this));
            });

            // Show/hide help text on focus
            this.form.find('.spg-input, .spg-textarea, .spg-select').on('focus', function() {
                const helpId = $(this).attr('aria-describedby');
                if (helpId) {
                    $('#' + helpId).fadeIn(200);
                }
            });

            // Enhanced keyboard navigation
            this.setupKeyboardNavigation();
        },

        /**
         * Validate individual field
         */
        validateField: function($field) {
            const value = $field.val().trim();
            const isRequired = $field.prop('required');
            const isValid = !isRequired || value.length > 0;

            if ($field.hasClass('spg-textarea')) {
                if (!isValid) {
                    $field.attr('aria-invalid', 'true');
                    this.showFieldError($field, 'This field is required');
                } else {
                    $field.attr('aria-invalid', 'false');
                    this.hideFieldError($field);
                }
            } else if ($field.hasClass('spg-select')) {
                if (!isValid || value === '') {
                    $field.attr('aria-invalid', 'true');
                    this.showFieldError($field, 'Please select an option');
                } else {
                    $field.attr('aria-invalid', 'false');
                    this.hideFieldError($field);
                }
            } else if ($field.hasClass('spg-input')) {
                if (!isValid) {
                    $field.attr('aria-invalid', 'true');
                    this.showFieldError($field, 'This field is required');
                } else {
                    $field.attr('aria-invalid', 'false');
                    this.hideFieldError($field);
                }
            }
        },

        /**
         * Show field error message
         */
        showFieldError: function($field, message) {
            const $formField = $field.closest('.spg-form-field');
            if ($formField.find('.spg-field-error').length === 0) {
                $formField.append(
                    '<p class="spg-field-error" role="alert">' + message + '</p>'
                );
            }
            $field.addClass('spg-field-invalid');
        },

        /**
         * Hide field error message
         */
        hideFieldError: function($field) {
            const $formField = $field.closest('.spg-form-field');
            $formField.find('.spg-field-error').remove();
            $field.removeClass('spg-field-invalid');
        },

        /**
         * Update character count for textarea
         */
        updateCharacterCount: function($textarea) {
            const count = $textarea.val().length;
            let $counter = $textarea.siblings('.spg-char-count');

            if ($counter.length === 0) {
                $counter = $('<p class="spg-char-count" aria-live="polite"></p>');
                $textarea.after($counter);
            }

            $counter.text(count + ' characters');
        },

        /**
         * Enhance accessibility
         */
        enhanceAccessibility: function() {
            // Add ARIA live region for form feedback
            if ($('#spg-form-feedback').length === 0) {
                $('body').append(
                    '<div id="spg-form-feedback" class="sr-only" aria-live="polite" aria-atomic="true"></div>'
                );
            }

            // Add skip link if not present
            if ($('.spg-skip-link').length === 0) {
                $('.spg-form-card').before(
                    '<a href="#spg-form-actions" class="spg-skip-link">Skip to form actions</a>'
                );
            }

            // Ensure all form sections are properly marked
            this.form.find('.spg-form-section').attr('role', 'region').each(function(index) {
                const $section = $(this);
                const $title = $section.find('.spg-section-title');
                if ($title.length) {
                    const titleId = 'section-title-' + index;
                    $title.attr('id', titleId);
                    $section.attr('aria-labelledby', titleId);
                }
            });
        },

        /**
         * Setup form validation on submission
         */
        setupValidation: function() {
            const self = this;
            this.form.on('submit', function(e) {
                let isValid = true;
                const $feedbackRegion = $('#spg-form-feedback');

                $(this).find('.spg-input[required], .spg-textarea[required], .spg-select[required]').each(function() {
                    if (!$(this).val().trim() || ($(this).hasClass('spg-select') && $(this).val() === '')) {
                        self.validateField($(this));
                        isValid = false;
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    $feedbackRegion.text('Please fill in all required fields before proceeding.');
                    $(this).find('[aria-invalid="true"]:first').focus();
                } else {
                    $feedbackRegion.text('Form submitted successfully.');
                }
            });
        },

        /**
         * Setup keyboard navigation
         */
        setupKeyboardNavigation: function() {
            const self = this;
            const $formFields = this.form.find('.spg-input, .spg-textarea, .spg-select, .spg-btn');

            $formFields.on('keydown', function(e) {
                // Tab to next field (handled by browser default)
                if (e.key === 'Tab') {
                    const $current = $(e.target);
                    const $focusableElements = self.form.find(':focusable');
                    const currentIndex = $focusableElements.index($current);
                    // Default browser behavior handles Tab navigation
                }

                // Enter submits form with Ctrl
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    self.form.submit();
                }

                // Escape clears current field
                if (e.key === 'Escape') {
                    $(this).val('').trigger('change');
                }
            });
        },

        /**
         * Setup character count display
         */
        setupCharacterCount: function() {
            this.form.find('.spg-textarea').each(function() {
                const $textarea = $(this);
                const count = $textarea.val().length;
                $textarea.after(
                    '<p class="spg-char-count" aria-live="polite">' + count + ' characters</p>'
                );
            });
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        if ($('.spg-form').length) {
            SPG.init();
        }

        // Add smooth scroll behavior
        $(document).on('click', 'a[href^="#"]', function(e) {
            const target = $(this).attr('href');
            if ($(target).length) {
                e.preventDefault();
                $('html, body').animate({
                    scrollTop: $(target).offset().top - 20
                }, 300);
            }
        });

        // Handle preview card animations
        $('.spg-preview-card').fadeIn(500);

        // Enhance info card with smooth expansion
        $('.spg-info-card').on('mouseenter', function() {
            $(this).stop().animate({
                boxShadow: '0 10px 25px rgba(0, 0, 0, 0.15)'
            }, 300);
        }).on('mouseleave', function() {
            $(this).stop().animate({
                boxShadow: '0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.08)'
            }, 300);
        });
    });

})(jQuery);