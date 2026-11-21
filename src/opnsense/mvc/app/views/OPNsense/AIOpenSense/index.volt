<script type="text/javascript">
$(document).ready(function() {
    // Load settings
    $.ajax({
        url: '/api/aiopensense/settings/get',
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            if (data && data.aiopensense && data.aiopensense.general) {
                var g = data.aiopensense.general;
                $('#aiopensense-provider').val(g.provider || 'openrouter');
                $('#aiopensense-endpoint').val(g.api_endpoint || '');
                $('#aiopensense-model').val(g.model || '');
                $('#aiopensense-keyfile').val(g.api_key_file || '/conf/aiopensense.key');
                $('#aiopensense-send-context').prop('checked', (g.send_context || '1') === '1');
                $('#aiopensense-system-prompt').val(g.system_prompt || '');
                $('#aiopensense-enabled').val(g.enabled || '1');
            }
        }
    });

    $('#btn-send').on('click', function() {
        var msg = $('#chat-input').val().trim();
        if (!msg) return;
        addMessage('user', msg);
        $('#chat-input').val('');
        var thinkingId = addMessage('system', Lang._('Loading...'));
        $.ajax({
            url: '/api/aiopensense/chat/query',
            type: 'POST',
            dataType: 'json',
            data: { message: msg },
            success: function(data) {
                $('.chat-system:last').remove();
                if (data.status === 'ok') {
                    addMessage('assistant', data.reply);
                } else {
                    addMessage('error', data.message || 'Error');
                }
            },
            error: function(xhr) {
                $('.chat-system:last').remove();
                addMessage('error', 'Request failed');
            }
        });
    });

    $('#chat-input').on('keypress', function(e) {
        if (e.which === 13) { e.preventDefault(); $('#btn-send').click(); }
    });

    $('#btn-save-settings').on('click', function() {
        var data = {
            general: {
                enabled: $('#aiopensense-enabled').val(),
                provider: $('#aiopensense-provider').val(),
                api_endpoint: $('#aiopensense-endpoint').val(),
                model: $('#aiopensense-model').val(),
                api_key_file: $('#aiopensense-keyfile').val(),
                send_context: $('#aiopensense-send-context').is(':checked') ? '1' : '0',
                system_prompt: $('#aiopensense-system-prompt').val()
            }
        };
        $.ajax({
            url: '/api/aiopensense/settings/set',
            type: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function() { alert(Lang._('Settings saved')); },
            error: function() { alert(Lang._('Save failed')); }
        });
    });

    function addMessage(role, text) {
        var div = $('<div/>')
            .addClass('chat-msg' + (role === 'user' ? ' chat-user' : role === 'assistant' ? ' chat-ai' : role === 'error' ? ' chat-err' : ' chat-sys'))
            .html('<small class="text-muted"></small><div class="chat-text">' + text.replace(/\n/g, '<br>') + '</div>');
        $('#chat-messages').append(div);
        $('#chat-messages').scrollTop($('#chat-messages')[0].scrollHeight);
    }
});
</script>

<style>
    /* Match the OPNsense menu palette: dark surface, light text, orange accent. */
    #chat-messages,
    #settings-panel {
        background-color: #101218 !important;
        color: #ececec !important;
        border-bottom: 1px solid #2f3446 !important;
    }
    #chat-messages .chat-text { color: #ececec; }
    #chat-messages .chat-user .chat-text { color: #ffb38a; }
    #chat-messages .chat-sys .chat-text,
    #chat-messages .text-muted { color: #b9b9b9; }
    #chat-messages .chat-err .chat-text { color: #ff8a80; }
    #chat-input::placeholder { color: #8a8f9c; }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">OPNsense AI</h3>
                    <div class="box-tools pull-right">
                        <a href="/ui/aiopensense/guides/" class="btn btn-default btn-sm">
                            <span class="glyphicon glyphicon-book"></span> Guides
                        </a>
                    </div>
                </div>
                <div id="settings-panel" class="box-body" style="display:none; padding:12px;">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Cloud Provider</label>
                                <select id="aiopensense-provider" class="form-control">
                                    <option value="openrouter">OpenRouter (recommended, NVIDIA fallback)</option>
                                    <option value="nvidia">NVIDIA NIM (OpenRouter fallback)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>API Endpoint <small>(blank = preset)</small></label>
                                <input type="text" id="aiopensense-endpoint" class="form-control" placeholder="https://openrouter.ai/api/v1">
                            </div>
                            <div class="form-group">
                                <label>Model <small>(blank = preset)</small></label>
                                <input type="text" id="aiopensense-model" class="form-control" placeholder="nvidia/nemotron-3.5-lightning:free">
                            </div>
                            <div class="form-group">
                                <label>API Key File</label>
                                <input type="text" id="aiopensense-keyfile" class="form-control" value="/conf/aiopensense.key">
                                <small class="text-muted">Root-only file with the provider key. Keys are never stored in config.xml.</small>
                            </div>
                            <div class="checkbox">
                                <label><input type="checkbox" id="aiopensense-send-context" checked> Send firewall context with requests</label>
                            </div>
                            <div class="form-group">
                                <label>System Prompt</label>
                                <textarea id="aiopensense-system-prompt" class="form-control" rows="3">You are AIOpenSense, an AI assistant for OPNsense firewall administration. Help with firewall rules, ad blocking, Unbound DNS over TLS, and general configuration.</textarea>
                            </div>
                            <button type="button" id="btn-save-settings" class="btn btn-primary">Save Settings</button>
                        </div>
                    </div>
                </div>
                <div class="box-body" style="height:380px; overflow-y:auto; padding:12px;" id="chat-messages">
                    <div class="chat-sys">
                        <div class="chat-text">Welcome to OPNsense AI. I can help with firewall rules, ad blocking, Unbound DNS over TLS, and general OPNsense configuration. Ask me anything!</div>
                    </div>
                </div>
                <div class="box-footer">
                    <div class="input-group">
                        <input type="text" id="chat-input" class="form-control" placeholder="Ask about firewall rules, DNS, ad blocking..." autocomplete="off">
                        <span class="input-group-btn">
                            <button type="button" id="btn-send" class="btn btn-primary">Send</button>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
