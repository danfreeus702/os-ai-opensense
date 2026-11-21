<?php
/**
 * Hermes AI — Guides (Configuration Wizards) index page
 */
?>
<div class="page-header">
    <h1>Hermes AI — Configuration Guides</h1>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">Available Configuration Guides</h3>
                <div class="box-tools pull-right">
                    <a href="/ui/hermes/" class="btn btn-default btn-sm">
                        <span class="glyphicon glyphicon-chat"></span> Back to Chat
                    </a>
                </div>
            </div>
            <div class="box-body">
                <p class="lead">
                    Step-by-step configuration guides with AI assistance.
                    Each guide walks you through a complete OPNsense configuration task.
                </p>
                <div class="row">
                    {% for guide in guides %}
                    <div class="col-md-4">
                        <div class="box box-solid box-primary">
                            <div class="box-header with-border">
                                <h3 class="box-title">
                                    <span class="glyphicon glyphicon-{{ guide.icon }}"></span>
                                    {{ guide.name }}
                                </h3>
                            </div>
                            <div class="box-body">
                                <p>{{ guide.description }}</p>
                                <a href="/ui/hermes/guide/view/?id={{ guide.id }}"
                                   class="btn btn-primary btn-block">
                                    <span class="glyphicon glyphicon-play"></span> Start Guide
                                </a>
                            </div>
                            <div class="box-footer">
                                <small>
                                    {{ guide.steps|length }} steps
                                </small>
                            </div>
                        </div>
                    </div>
                    {% endfor %}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">About Configuration Guides</h3>
            </div>
            <div class="box-body">
                <p>
                    These guides use the Hermes AI assistant to provide context-aware guidance
                    at each step. The AI reads your current OPNsense configuration (firewall rules,
                    interfaces, NAT, DNS settings) and tailors its advice to your specific setup.
                </p>
                <ul>
                    <li><strong>Firewall Rules Setup</strong> — Configure essential firewall rules with AI recommendations for WAN/LAN traffic.</li>
                    <li><strong>Unbound DNS over TLS</strong> — Set up encrypted DNS with DNSSEC validation.</li>
                    <li><strong>Ad Blocking Setup</strong> — Configure network-wide ad blocking with recommended blocklists.</li>
                </ul>
                <p>
                    <strong>Note:</strong> AI suggestions are advisory. Always review configuration changes before applying them through the OPNsense GUI.
                    The AI does not modify your firewall configuration directly.
                </p>
            </div>
        </div>
    </div>
</div>
