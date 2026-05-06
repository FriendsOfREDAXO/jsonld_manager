/**
 * JSON-LD Manager - Backend JavaScript
 * 
 * Interaktive Funktionalitäten für das Backend-Interface
 * 
 * @package JsonldManager
 * @version 1.0.0
 */

(function($) {
    'use strict';
    
    /**
     * JSON-LD Manager Hauptklasse
     */
    var JsonldManager = {
        
        /**
         * Initialisierung
         */
        init: function() {
            this.bindEvents();
            this.initComponents();
        },
        
        /**
         * Event-Bindings
         */
        bindEvents: function() {
            // Live-Vorschau
            $(document).on('change', '.mapping-source, .mapping-fallback', this.updatePreview);
            $(document).on('keyup', '.mapping-source, .mapping-fallback', this.debounce(this.updatePreview, 500));
            
            // Schema-Type Änderung
            $(document).on('change', '.schema-type-select', this.onSchemaTypeChange);
            
            // Property-Mapping hinzufügen
            $(document).on('click', '.add-property-btn', this.addPropertyMapping);
            
            // Property-Mapping entfernen
            $(document).on('click', '.remove-property-btn', this.removePropertyMapping);
            
            // URL-Regel testen
            $(document).on('click', '.test-rule-btn', this.testUrlRule);
            
            // JSON-LD validieren
            $(document).on('click', '.validate-jsonld-btn', this.validateJsonLd);
            
            // Ajax-Formular-Handling
            $(document).on('submit', '.ajax-form', this.handleAjaxForm);
            
            // Copy-to-Clipboard
            $(document).on('click', '.copy-to-clipboard', this.copyToClipboard);
            
            // Accordion-Toggle
            $(document).on('click', '.jsonld-accordion-header', this.toggleAccordion);
        },
        
        /**
         * Komponenten initialisieren
         */
        initComponents: function() {
            // Code-Editoren initialisieren
            this.initCodeEditors();
            
            // Tooltips aktivieren
            this.initTooltips();
            
            // Live-Vorschau initial laden
            this.updatePreview();
            
            // Auto-Save für Formulare
            this.initAutoSave();
        },
        
        /**
         * Code-Editoren initialisieren (falls verfügbar)
         */
        initCodeEditors: function() {
            if (typeof CodeMirror !== 'undefined') {
                $('.jsonld-code-editor').each(function() {
                    var mode = $(this).data('mode') || 'application/json';
                    CodeMirror.fromTextArea(this, {
                        mode: mode,
                        theme: 'monokai',
                        lineNumbers: true,
                        autoCloseBrackets: true,
                        matchBrackets: true,
                        indentUnit: 2,
                        tabSize: 2,
                        lineWrapping: true
                    });
                });
            }
        },
        
        /**
         * Tooltips initialisieren
         */
        initTooltips: function() {
            $('[data-toggle="tooltip"]').tooltip({
                placement: 'top',
                trigger: 'hover',
                delay: { show: 500, hide: 100 }
            });
        },
        
        /**
         * Auto-Save initialisieren
         */
        initAutoSave: function() {
            var autoSaveTimeout;
            
            $(document).on('change keyup', '.auto-save-field', function() {
                clearTimeout(autoSaveTimeout);
                autoSaveTimeout = setTimeout(function() {
                    JsonldManager.autoSaveForm();
                }, 2000);
            });
        },
        
        /**
         * Live-Vorschau aktualisieren
         */
        updatePreview: function() {
            var $form = $('.jsonld-mapping-form');
            if ($form.length === 0) return;
            
            var formData = $form.serialize();
            
            $.ajax({
                url: rex.backend_url + '?rex-api-call=jsonld_preview',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('.jsonld-preview-content').html(response.html);
                        $('.jsonld-preview-json').text(JSON.stringify(response.data, null, 2));
                        JsonldManager.highlightJson($('.jsonld-preview-json'));
                    } else {
                        $('.jsonld-preview-content').html('<div class="alert alert-danger">' + response.message + '</div>');
                    }
                },
                error: function() {
                    $('.jsonld-preview-content').html('<div class="alert alert-warning">Vorschau konnte nicht geladen werden.</div>');
                }
            });
        },
        
        /**
         * Schema-Type Änderung
         */
        onSchemaTypeChange: function() {
            var schemaType = $(this).val();
            var $container = $('.property-mappings-container');
            
            // Template für Schema-Type laden
            $.ajax({
                url: rex.backend_url + '?rex-api-call=jsonld_schema_template',
                type: 'POST',
                data: { schema_type: schemaType },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $container.html(response.html);
                        JsonldManager.updatePreview();
                    }
                }
            });
        },
        
        /**
         * Property-Mapping hinzufügen
         */
        addPropertyMapping: function(e) {
            e.preventDefault();
            
            var $container = $('.property-mappings-container');
            var template = $('.property-mapping-template').html();
            var index = $container.find('.property-mapping').length;
            
            template = template.replace(/\{INDEX\}/g, index);
            
            $container.append(template);
            JsonldManager.updatePreview();
        },
        
        /**
         * Property-Mapping entfernen
         */
        removePropertyMapping: function(e) {
            e.preventDefault();
            
            if (confirm('Möchten Sie diese Property-Zuordnung wirklich entfernen?')) {
                $(this).closest('.property-mapping').fadeOut(300, function() {
                    $(this).remove();
                    JsonldManager.updatePreview();
                });
            }
        },
        
        /**
         * URL-Regel testen
         */
        testUrlRule: function(e) {
            e.preventDefault();
            
            var $form = $(this).closest('form');
            var testUrl = $('.test-url-input').val();
            var testParams = $('.test-params-input').val();
            
            if (!testUrl) {
                alert('Bitte geben Sie eine Test-URL ein.');
                return;
            }
            
            var formData = $form.serialize() + '&test_url=' + encodeURIComponent(testUrl) + '&test_params=' + encodeURIComponent(testParams);
            
            $.ajax({
                url: rex.backend_url + '?rex-api-call=jsonld_test_rule',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    var resultClass = response.match ? 'alert-success' : 'alert-warning';
                    var resultText = response.match ? 'Regel passt!' : 'Regel passt nicht.';
                    
                    var html = '<div class="alert ' + resultClass + '"><strong>' + resultText + '</strong>';
                    
                    if (response.extracted_data && Object.keys(response.extracted_data).length > 0) {
                        html += '<pre>' + JSON.stringify(response.extracted_data, null, 2) + '</pre>';
                    }
                    
                    if (response.errors && response.errors.length > 0) {
                        html += '<ul><li>' + response.errors.join('</li><li>') + '</li></ul>';
                    }
                    
                    html += '</div>';
                    
                    $('.test-result-container').html(html);
                },
                error: function() {
                    $('.test-result-container').html('<div class="alert alert-danger">Fehler beim Testen der Regel.</div>');
                }
            });
        },
        
        /**
         * JSON-LD validieren
         */
        validateJsonLd: function(e) {
            e.preventDefault();
            
            var articleId = $(this).data('article-id');
            var schemaType = $(this).data('schema-type');
            
            $.ajax({
                url: rex.backend_url + '?rex-api-call=jsonld_validate',
                type: 'POST',
                data: {
                    article_id: articleId,
                    schema_type: schemaType
                },
                dataType: 'json',
                beforeSend: function() {
                    $('.validation-result-container').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Validiere...</div>');
                },
                success: function(response) {
                    var resultClass = response.valid ? 'alert-success' : 'alert-danger';
                    var resultIcon = response.valid ? 'fa-check' : 'fa-times';
                    var resultText = response.valid ? 'JSON-LD ist gültig!' : 'JSON-LD ist ungültig!';
                    
                    var html = '<div class="alert ' + resultClass + '"><i class="fa ' + resultIcon + '"></i> <strong>' + resultText + '</strong></div>';
                    
                    if (response.json_ld) {
                        html += '<pre class="jsonld-output">' + response.json_ld + '</pre>';
                    }
                    
                    if (response.errors && response.errors.length > 0) {
                        html += '<div class="alert alert-warning"><strong>Fehler:</strong><ul><li>' + response.errors.join('</li><li>') + '</li></ul></div>';
                    }
                    
                    $('.validation-result-container').html(html);
                    JsonldManager.highlightJson($('.jsonld-output'));
                },
                error: function() {
                    $('.validation-result-container').html('<div class="alert alert-danger">Fehler bei der Validierung.</div>');
                }
            });
        },
        
        /**
         * Ajax-Formular handhaben
         */
        handleAjaxForm: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');
            var originalText = $submitBtn.html();
            
            $.ajax({
                url: $form.attr('action'),
                type: $form.attr('method') || 'POST',
                data: $form.serialize(),
                dataType: 'json',
                beforeSend: function() {
                    $submitBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Speichere...');
                },
                success: function(response) {
                    if (response.success) {
                        JsonldManager.showNotification(response.message, 'success');
                        if (response.redirect) {
                            setTimeout(function() {
                                window.location.href = response.redirect;
                            }, 1000);
                        }
                    } else {
                        JsonldManager.showNotification(response.message, 'error');
                    }
                },
                error: function() {
                    JsonldManager.showNotification('Ein Fehler ist aufgetreten.', 'error');
                },
                complete: function() {
                    $submitBtn.prop('disabled', false).html(originalText);
                }
            });
        },
        
        /**
         * Auto-Save-Formular
         */
        autoSaveForm: function() {
            var $form = $('.auto-save-form');
            if ($form.length === 0) return;
            
            $.ajax({
                url: $form.attr('action'),
                type: 'POST',
                data: $form.serialize() + '&auto_save=1',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        JsonldManager.showNotification('Automatisch gespeichert', 'info', 2000);
                    }
                }
            });
        },
        
        /**
         * Copy-to-Clipboard
         */
        copyToClipboard: function(e) {
            e.preventDefault();
            
            var text = $(this).data('text') || $($(this).data('target')).text();
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    JsonldManager.showNotification('In Zwischenablage kopiert', 'success', 2000);
                });
            } else {
                // Fallback für ältere Browser
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                JsonldManager.showNotification('In Zwischenablage kopiert', 'success', 2000);
            }
        },
        
        /**
         * Accordion-Toggle
         */
        toggleAccordion: function(e) {
            e.preventDefault();
            
            var $header = $(this);
            var $content = $header.next('.jsonld-accordion-content');
            var $icon = $header.find('.toggle-icon');
            
            $content.slideToggle(300);
            $icon.toggleClass('fa-chevron-down fa-chevron-up');
        },
        
        /**
         * JSON-Syntax-Highlighting
         */
        highlightJson: function($element) {
            if (typeof hljs !== 'undefined') {
                $element.each(function() {
                    hljs.highlightBlock(this);
                });
            }
        },
        
        /**
         * Benachrichtigung anzeigen
         */
        showNotification: function(message, type, duration) {
            type = type || 'info';
            duration = duration || 5000;
            
            var alertClass = {
                success: 'alert-success',
                error: 'alert-danger',
                warning: 'alert-warning',
                info: 'alert-info'
            }[type] || 'alert-info';
            
            var $notification = $('<div class="alert ' + alertClass + ' alert-dismissible jsonld-notification" role="alert">' +
                '<button type="button" class="close" data-dismiss="alert">' +
                '<span aria-hidden="true">&times;</span>' +
                '</button>' +
                message +
                '</div>');
            
            $('body').append($notification);
            
            $notification.css({
                position: 'fixed',
                top: '20px',
                right: '20px',
                zIndex: 9999,
                minWidth: '300px',
                maxWidth: '500px'
            }).fadeIn(300);
            
            setTimeout(function() {
                $notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, duration);
        },
        
        /**
         * Debounce-Funktion
         */
        debounce: function(func, wait) {
            var timeout;
            return function executedFunction() {
                var context = this;
                var args = arguments;
                var later = function() {
                    timeout = null;
                    func.apply(context, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };
    
    /**
     * DOM Ready
     */
    $(document).ready(function() {
        JsonldManager.init();
    });
    
    /**
     * REDAXO Ready Event
     */
    $(document).on('rex:ready', function() {
        JsonldManager.init();
    });
    
    // Global verfügbar machen
    window.JsonldManager = JsonldManager;
    
})(jQuery);