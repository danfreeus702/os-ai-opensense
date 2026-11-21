<script type="text/javascript">
$(document).ready(function() {
    // Load settings
    $.ajax({
        url: '/api/hermes/settings/get',
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            if (data && data.hermes && data.hermes.general) {
                var g = data.hermes.general;
                $('#hermes-ollama-host').val(g.ollama_host || 'http://127.0.0.1:11434');
                $('#hermes-ollama-model').val(g.ollama_model || 'llama3');
                $('#hermes-system-prompt').val(g.system_prompt || '');
                $('#hermes-enabled').val(g.enabled || '1');
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
            url: '/api/hermes/chat/query',
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
                enabled: $('#hermes-enabled').val(),
                ollama_host: $('#hermes-ollama-host').val(),
                ollama_model: $('#hermes-ollama-model').val(),
                system_prompt: $('#hermes-system-prompt').val()
            }
        };
        $.ajax({
            url: '/api/hermes/settings/set',
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

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Hermes AI</h3>
                    <div class="box-tools pull-right">
                        <a href="/ui/hermes/guides/" class="btn btn-default btn-sm">
                            <span class="glyphicon glyphicon-book"></span> Guides
                        </a>
                </div>
                <div id="settings-panel" class="box-body" style="display:none; background:#f5f5f5; border-bottom:1px solid #ddd; padding:12px;">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Ollama Host</label>
                                <input type="text" id="hermes-ollama-host" class="form-control" value="http://127.0.0.1:11434" placeholder="http://127.0.0.1:11434">
                            </div>
                            <div class="form-group">
                                <label>Model</label>
                                <input type="text" id="hermes-ollama-model" class="form-control" value="llama3" placeholder="llama3">
                            </div>
                            <div class="form-group">
                                <label>System Prompt</label>
                                <textarea id="hermes-system-prompt" class="form-control" rows="3">You are Hermes, an AI assistant for OPNsense firewall administration. Help with firewall rules, ad blocking, Unbound DNS over TLS, and general configuration.</textarea>
                            </div>
                            <button type="button" id="btn-save-settings" class="btn btn-primary">Save Settings</button>
                        </div>
                    </div>
                </div>
                <div class="box-body" style="height:380px; overflow-y:auto; background:#fcfcfc; padding:12px; border-bottom:1px solid #ddd;" id="chat-messages">
                    <div class="chat-sys">
                        <div class="chat-text">Welcome to Hermes AI. I can help with firewall rules, ad blocking, Unbound DNS over TLS, and general OPNsense configuration. Ask me anything!</div>
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
