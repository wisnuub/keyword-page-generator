/**
 * Keyword Page Generator — admin screen.
 */
(function ($) {
    'use strict';

    var cfg = window.kpgen;
    var t = cfg.i18n;
    var job = null;
    var countTimer = null;

    function fmt(str) {
        var args = Array.prototype.slice.call(arguments, 1), i = 0;
        return String(str).replace(/%(?:(\d+)\$)?[sd]/g, function (m, pos) {
            var v = pos ? args[pos - 1] : args[i++];
            return v === undefined ? m : v;
        });
    }

    function post(action, data) {
        return $.post(cfg.ajaxUrl, $.extend({ action: 'kpgen_' + action, nonce: cfg.nonce }, data || {}));
    }

    function errorText(res) {
        return res && typeof res.data === 'string' ? res.data : t.error;
    }

    /* ---------- tabs ---------- */

    function showTab(name) {
        $('.kpgen-tabs .nav-tab').removeClass('nav-tab-active').filter('[data-tab="' + name + '"]').addClass('nav-tab-active');
        $('.kpgen-panel').attr('hidden', true).filter('[data-panel="' + name + '"]').attr('hidden', false);
    }

    $(document).on('click', '.kpgen-tabs .nav-tab', function (e) {
        e.preventDefault();
        var name = $(this).data('tab');
        showTab(name);
        history.replaceState(null, '', '#' + name);
    });

    /* ---------- templates ---------- */

    function loadTemplates() {
        var $sel = $('#kpgen-template').empty().append($('<option>').val('').text('…'));
        post('templates', { post_type: $('#kpgen-type').val() }).done(function (res) {
            $sel.empty().append($('<option>').val('').text(t.select));
            (res.data || []).forEach(function (p) {
                $sel.append($('<option>').val(p.id).text(p.title));
            });
        });
    }

    /* ---------- keyword pairs ---------- */

    var pairIndex = 0;

    function addPair(find, values) {
        var i = pairIndex++;
        var $pair = $('<div class="kpgen-pair">').attr('data-index', i);
        $pair.append(
            $('<label>').append($('<span>').text(t.keyword),
                $('<input type="text" class="kpgen-find">').attr('name', 'pairs[' + i + '][find]').val(find || '').attr('placeholder', 'Melbourne')),
            $('<label>').append($('<span>').text(t.values),
                $('<textarea rows="4" class="kpgen-values">').attr('name', 'pairs[' + i + '][values]').val(values || '').attr('placeholder', 'Sydney\nBrisbane\nPerth')),
            $('<button type="button" class="button-link kpgen-remove">').text(t.removePair)
        );
        $('#kpgen-pairs').append($pair);
        updatePairs();
    }

    function updatePairs() {
        var $pairs = $('#kpgen-pairs .kpgen-pair');
        $pairs.find('.kpgen-remove').toggle($pairs.length > 1);
        $('#kpgen-mode').attr('hidden', $pairs.length < 2);
        scheduleCount();
    }

    $(document).on('click', '#kpgen-add-pair', function () { addPair(); });
    $(document).on('click', '.kpgen-remove', function () {
        $(this).closest('.kpgen-pair').remove();
        updatePairs();
    });

    /* CSV: first row = keywords, following rows = values for each column. */
    $('#kpgen-csv').on('change', function () {
        var file = this.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function () {
            var rows = parseCsv(String(reader.result)).filter(function (r) { return r.some(function (c) { return c.trim() !== ''; }); });
            if (rows.length < 2) { window.alert(t.csvBad); return; }
            $('#kpgen-pairs').empty();
            rows[0].forEach(function (head, col) {
                var vals = rows.slice(1).map(function (r) { return (r[col] || '').trim(); }).filter(Boolean);
                if (head.trim() && vals.length) addPair(head.trim(), vals.join('\n'));
            });
            if (!$('#kpgen-pairs .kpgen-pair').length) addPair();
        };
        reader.readAsText(file);
        this.value = '';
    });

    function parseCsv(text) {
        var rows = [], row = [], cell = '', quoted = false;
        for (var i = 0; i < text.length; i++) {
            var c = text[i];
            if (quoted) {
                if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; }
                else if (c === '"') quoted = false;
                else cell += c;
            } else if (c === '"') quoted = true;
            else if (c === ',' || c === ';' || c === '\t') { row.push(cell); cell = ''; }
            else if (c === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; }
            else if (c !== '\r') cell += c;
        }
        if (cell || row.length) { row.push(cell); rows.push(row); }
        return rows;
    }

    /* ---------- live count ---------- */

    function formData(extra) {
        var data = $('#kpgen-form').serializeArray().reduce(function (o, f) { o[f.name] = f.value; return o; }, {});
        return $.extend(data, extra || {});
    }

    function scheduleCount() {
        clearTimeout(countTimer);
        countTimer = setTimeout(updateCount, 350);
    }

    function updateCount() {
        post('count', formData()).done(function (res) {
            var d = res.data || {};
            var $titles = $('#kpgen-titles').empty();
            $('#kpgen-count').text(d.total ? (d.total === 1 ? t.page : fmt(t.pages, d.total)) : (d.message || '—'));
            (d.titles || []).forEach(function (title) { $titles.append($('<li>').text(title)); });
            if (d.total > (d.titles || []).length) $titles.append($('<li class="kpgen-more">').text(fmt(t.more, d.total - d.titles.length)));
            $('#kpgen-missing').attr('hidden', !(d.missing && d.missing.length)).text(d.missing && d.missing.length ? fmt(t.missing, d.missing.join(', ')) : '');
            $('#kpgen-generate, #kpgen-preview, #kpgen-schedule').prop('disabled', !d.total || !!job);
            $('#kpgen-generate').data('total', d.total || 0);
        });
    }

    $('#kpgen-form').on('input change', 'input, select, textarea', function (e) {
        if (e.target.id === 'kpgen-type') return;
        if (e.target.name === 'run' || e.target.id === 'kpgen-when') return;
        scheduleCount();
    });
    $('#kpgen-type').on('change', function () { loadTemplates(); scheduleCount(); });

    /* ---------- preview ---------- */

    $('#kpgen-preview').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        post('preview', formData()).done(function (res) {
            if (!res.success) { window.alert(errorText(res)); return; }
            setStatus(false);
            log(t.previewReady, res.data.url, t.open, res.data.warning);
        }).fail(function () { window.alert(t.error); }).always(function () { $btn.prop('disabled', false); });
    });

    /* ---------- generate ---------- */

    $('#kpgen-generate').on('click', function () {
        var total = $(this).data('total') || 0;
        if (total > 20 && !window.confirm(fmt(t.confirmMany, total))) return;
        start('now');
    });

    $('#kpgen-schedule').on('click', function () {
        start($('input[name="run"]:checked').val(), $('#kpgen-when').val());
    });

    function start(run, when) {
        $('#kpgen-generate, #kpgen-preview, #kpgen-schedule').prop('disabled', true);
        post('start', formData({ run: run, when: when || '' })).done(function (res) {
            if (!res.success) {
                window.alert(errorText(res));
                $('#kpgen-generate, #kpgen-preview, #kpgen-schedule').prop('disabled', false);
                return;
            }
            if (res.data.run !== 'now') {
                setStatus(false);
                $('#kpgen-status-text').text(res.data.run === 'timed' ? fmt(t.scheduled, res.data.run_at) : t.background);
                $('#kpgen-generate, #kpgen-preview, #kpgen-schedule').prop('disabled', false);
                return;
            }
            job = { id: res.data.job, total: res.data.total, created: 0 };
            setStatus(true);
            progress(0);
            step();
        }).fail(function () {
            window.alert(t.error);
            $('#kpgen-generate, #kpgen-preview, #kpgen-schedule').prop('disabled', false);
        });
    }

    function step() {
        if (!job) return;
        post('step', { job: job.id }).done(function (res) {
            if (!res.success) { finish(errorText(res)); return; }
            var d = res.data;
            job.created = d.created;
            if (d.last) {
                if (d.last.ok) log(d.last.title, d.last.url, null, d.last.warning);
                else log(d.last.title + ': ' + d.last.error);
            }
            progress(d.done);
            if (d.finished) finish(fmt(job.cancelled ? t.cancelled : t.finished, d.created));
            else step();
        }).fail(function () {
            // A slow AI call can hit a proxy timeout; the page is usually created anyway. Keep going.
            setTimeout(step, 3000);
        });
    }

    $('#kpgen-cancel').on('click', function () {
        if (!job) return;
        job.cancelled = true;
        post('cancel', { job: job.id });
    });

    function progress(done) {
        var pct = job.total ? Math.round(done / job.total * 100) : 100;
        $('#kpgen-bar').css('width', pct + '%');
        $('#kpgen-status-text').text(fmt(t.progress, Math.min(done + 1, job.total), job.total));
    }

    function finish(message) {
        $('#kpgen-status-text').text(message + ' ').append(
            $('<a href="#history">').text(t.viewHistory).on('click', function (e) {
                e.preventDefault();
                location.hash = 'history';
                location.reload();
            })
        );
        $('#kpgen-cancel').attr('hidden', true);
        $('#kpgen-bar').css('width', '100%');
        job = null;
        updateCount();
    }

    function setStatus(running) {
        $('#kpgen-status').attr('hidden', false);
        $('#kpgen-cancel').attr('hidden', !running);
        $('#kpgen-bar').parent().attr('hidden', !running);
        $('#kpgen-log').empty();
        $('#kpgen-status-text').text('');
    }

    function log(text, url, linkText, warning) {
        var $li = $('<li>');
        if (url) $li.append($('<a target="_blank" rel="noopener">').attr('href', url).text(linkText ? text + ' ' + linkText : text));
        else $li.text(text);
        if (warning) $li.append($('<span class="kpgen-log-warning">').text(' — ' + warning));
        $('#kpgen-log').prepend($li);
    }

    /* ---------- history ---------- */

    $(document).on('click', '.kpgen-trash', function () {
        var $btn = $(this);
        if (!window.confirm(fmt(t.confirmTrash, $btn.data('count')))) return;
        $btn.prop('disabled', true);
        post('trash_batch', { batch: $btn.data('batch') }).done(function (res) {
            if (!res.success) { window.alert(errorText(res)); $btn.prop('disabled', false); return; }
            $btn.replaceWith($('<span>').text(fmt(t.trashed, res.data.trashed)));
        });
    });

    /* ---------- AI settings ---------- */

    function syncProvider() {
        var p = cfg.providers[$('#kpgen-provider').val()] || {};
        var $list = $('#kpgen-models').empty();
        (p.models || []).forEach(function (m) { $list.append($('<option>').val(m)); });
        $('#kpgen-key-link').attr('href', p.key_url || '#');
    }

    $('#kpgen-provider').on('change', function () {
        var p = cfg.providers[$(this).val()] || {};
        $('#kpgen-model').val(p.models && p.models.length ? p.models[0] : '');
        syncProvider();
    });

    function syncSource() {
        var $checked = $('input[name="source"]:checked');
        var own = !$checked.length || $checked.val() === 'key';
        $('.kpgen-key-fields').toggle(own);
    }
    $(document).on('change', 'input[name="source"]', syncSource);

    $('#kpgen-ai-form').on('submit', function (e) {
        e.preventDefault();
        var $out = $('#kpgen-ai-result').text('…');
        post('save_ai', $(this).serializeArray().reduce(function (o, f) { o[f.name] = f.value; return o; }, {})).done(function (res) {
            $out.text(res.success ? t.saved : errorText(res));
            if (res.success) {
                $('#kpgen-key').val('');
                cfg.aiReady = !!res.data.ready;
                $('#kpgen-ai').prop('disabled', !cfg.aiReady);
            }
        });
    });

    $('#kpgen-test').on('click', function () {
        var $out = $('#kpgen-ai-result').text(t.testing);
        post('test_ai').done(function (res) {
            $out.text(res.success ? t.testOk : errorText(res));
        }).fail(function () { $out.text(t.error); });
    });

    /* ---------- init ---------- */

    $(function () {
        addPair();
        loadTemplates();
        syncProvider();
        syncSource();
        var hash = (location.hash || '').replace('#', '');
        if (['history', 'ai'].indexOf(hash) !== -1) showTab(hash);
    });

})(jQuery);
