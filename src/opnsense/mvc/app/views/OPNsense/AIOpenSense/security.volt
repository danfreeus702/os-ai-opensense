<?php
/**
 * OPNsense AI - Security Wizard
 */
?>
<div class="page-header">
    <h1>OPNsense AI - Security Wizard</h1>
    <p class="lead">Pick a security level. Review the plan. Apply with one click.</p>
</div>

<div class="row" id="level-cards">
    <!-- populated by JS -->
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Feature comparison</h3>
            </div>
            <div class="box-body">
                <table class="table table-striped" id="compare-table">
                    <thead><tr><th>Feature</th><th>Level 1</th><th>Level 2</th><th>Level 3</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row" id="plan-panel" style="display:none;">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title" id="plan-title">Review plan</h3>
            </div>
            <div class="box-body" id="plan-body"></div>
            <div class="box-body" id="preview-body" style="border-top:1px solid #2f3446;"></div>
            <div class="box-footer">
                <label class="checkbox-inline">
                    <input type="checkbox" id="tailscale-opt"> Include Tailscale exit node (Level 3 only)
                </label>
                <button type="button" class="btn btn-warning" id="btn-plan">Refresh plan</button>
                <button type="button" class="btn btn-danger" id="btn-apply">Apply selected level</button>
            </div>
        </div>
    </div>
</div>

<div class="row" id="result-panel" style="display:none;">
    <div class="col-md-12">
        <div class="box" id="result-box">
            <div class="box-header"><h3 class="box-title">Result</h3></div>
            <div class="box-body" id="result-body"></div>
            <div class="box-footer">
                <button type="button" class="btn btn-default" id="btn-undo">
                    <span class="glyphicon glyphicon-backward"></span> Undo last change
                </button>
                <span id="snapshot-note" class="text-muted"></span>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
var selectedLevel = 1;

function iconHtml(ok) {
    return ok
        ? '<span class="glyphicon glyphicon-ok text-success"></span>'
        : '<span class="glyphicon glyphicon-minus text-muted"></span>';
}

$(function () {
    $.getJSON('/api/aiopensense/securityWizard/levels', function (data) {
        if (data.status !== 'ok') return;
        var cards = '';
        $.each(data.levels, function (_, l) {
            cards += '<div class="col-md-4"><div class="box box-solid box-' +
                (l.id === 'level3' ? 'danger' : l.id === 'level2' ? 'warning' : 'success') +
                '"><div class="box-header"><h3 class="box-title"><span class="glyphicon glyphicon-' +
                l.icon + '"></span> ' + l.name + '</h3></div><div class="box-body"><p>' +
                l.description + '</p><button class="btn btn-primary btn-block btn-select" data-level="' +
                (l.id === 'level1' ? 1 : l.id === 'level2' ? 2 : 3) + '">Select</button></div></div></div>';
        });
        $('#level-cards').html(cards);
        $('.btn-select').on('click', function () {
            selectedLevel = parseInt($(this).data('level'), 10);
            $('#plan-panel').show();
            loadPlan();
        });

        var rows = '';
        $.each(data.comparison, function (_, c) {
            rows += '<tr><td>' + c.feature + '</td><td>' + iconHtml(c.level1) + '</td><td>' +
                iconHtml(c.level2) + '</td><td>' + iconHtml(c.level3) + '</td></tr>';
        });
        $('#compare-table tbody').html(rows);
    });

    function loadPlan() {
        var ts = $('#tailscale-opt').is(':checked') && selectedLevel === 3 ? '1' : '0';
        $.ajax({
            url: '/api/aiopensense/securityWizard/plan',
            type: 'POST',
            dataType: 'json',
            data: { level: selectedLevel, tailscale_exit: ts },
            success: function (d) {
                if (d.status !== 'ok') { $('#plan-body').text(d.message); return; }
                $('#plan-title').text(d.plan.name);
                var html = '<ol>';
                $.each(d.plan.steps, function (_, s) {
                    html += '<li><strong>' + s.title + '</strong> <small class="label label-' +
                        (s.risk === 'high' ? 'danger' : s.risk === 'medium' ? 'warning' : 'success') +
                        '">' + s.risk + '</small><br><span class="text-muted">' + s.detail + '</span></li>';
                });
                html += '</ol><p><em>' + d.plan.note + '</em></p>';
                $('#plan-body').html(html);
                loadPreview();
            }
        });
    }

    function loadPreview() {
        var ts = $('#tailscale-opt').is(':checked') && selectedLevel === 3 ? '1' : '0';
        $.ajax({
            url: '/api/aiopensense/securityWizard/preview',
            type: 'POST',
            dataType: 'json',
            data: { level: selectedLevel, tailscale_exit: ts },
            success: function (d) {
                if (d.status !== 'ok') return;
                var html = '<h4>What will change</h4><table class="table table-condensed table-striped">' +
                    '<thead><tr><th>Change</th><th>Before</th><th>After</th><th>Where in the GUI</th></tr></thead><tbody>';
                $.each(d.changes, function (_, c) {
                    html += '<tr><td><strong>' + c.title + '</strong><br><span class="text-muted">' +
                        c.plain + '</span><br><a href="' + c.doc_url + '" target="_blank" rel="noopener">Official docs</a></td>' +
                        '<td>' + c.before + '</td><td>' + c.after + '</td><td>' + c.gui_path + '</td></tr>';
                });
                html += '</tbody></table>';
                $('#preview-body').html(html);
            }
        });
    }

    $('#btn-plan').on('click', loadPlan);
    $('#tailscale-opt').on('change', loadPlan);

    $('#btn-apply').on('click', function () {
        if (!confirm('Apply security level ' + selectedLevel + '? Review the change table above first. A rollback snapshot will be saved automatically.')) return;
        var ts = $('#tailscale-opt').is(':checked') && selectedLevel === 3 ? '1' : '0';
        $.ajax({
            url: '/api/aiopensense/securityWizard/apply',
            type: 'POST',
            dataType: 'json',
            data: { level: selectedLevel, tailscale_exit: ts, confirm: 'yes' },
            success: function (d) {
                $('#result-panel').show();
                $('#result-box').removeClass('box-success box-warning').addClass(d.status === 'ok' ? 'box-success' : 'box-warning');
                if (d.snapshot) $('#snapshot-note').text(d.snapshot);
                var html = '<p>' + d.message + '</p><ul>';
                $.each(d.results || [], function (_, r) {
                    html += '<li>' + (r.ok ? '&#10003;' : '&#10007;') + ' ' + r.action +
                        (r.output ? ' <small>' + r.output + '</small>' : '') + '</li>';
                });
                html += '</ul>';
                $('#result-body').html(html);
            }
        });
    });

    $('#btn-undo').on('click', function () {
        if (!confirm('Restore the configuration from before the last change?')) return;
        $.ajax({
            url: '/api/aiopensense/securityWizard/undo',
            type: 'POST',
            dataType: 'json',
            data: { confirm: 'yes' },
            success: function (d) {
                $('#result-panel').show();
                $('#result-box').removeClass('box-success box-warning')
                    .addClass(d.status === 'ok' ? 'box-success' : 'box-warning');
                $('#result-body').html('<p>' + d.message + '</p><pre>' +
                    (d.output || '') + '</pre>');
            }
        });
    });
});
</script>
