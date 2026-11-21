<?php
/**
 * Hermes AI — Single Configuration Guide view
 */
?>
<div class="page-header">
    <h1>Hermes AI — {{ guide.name }}</h1>
</div>

<div class="row">
    <div class="col-md-12">
        <!-- Guide progress bar -->
        <div class="box box-solid box-info">
            <div class="box-body">
                <div class="pull-left">
                    <strong>Guide:</strong> {{ guide.name }}
                </div>
                <div class="pull-right">
                    <strong>Step {{ stepIndex + 1 }} of {{ steps|length }}</strong>
                </div>
                <div class="clearfix"></div>
                <hr>
                <div class="progress" style="margin-bottom: 0;">
                    <div class="progress-bar progress-bar-info" role="progressbar"
                         style="width: {{ (stepIndex + 1) * 100 / steps|length }}%">
                        {{ (stepIndex + 1) * 100 / steps|length }}%
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <span class="glyphicon glyphicon-hand-right"></span>
                    Step {{ stepIndex + 1 }}: {{ currentStep.title }}
                </h3>
                <div class="box-tools pull-right">
                    {% if stepIndex > 0 %}
                    <a href="?id={{ guide.id }}&step={{ steps[stepIndex - 1].id }}"
                       class="btn btn-default btn-sm">
                        <span class="glyphicon glyphicon-chevron-left"></span> Previous
                    </a>
                    {% endif %}
                    {% if stepIndex < steps|length - 1 %}
                    <a href="?id={{ guide.id }}&step={{ steps[stepIndex + 1].id }}"
                       class="btn btn-primary btn-sm">
                        Next <span class="glyphicon glyphicon-chevron-right"></span>
                    </a>
                    {% else %}
                    <a href="/ui/hermes/guides/"
                       class="btn btn-success btn-sm">
                        <span class="glyphicon glyphicon-check"></span> Complete
                    </a>
                    {% endif %}
                </div>
            </div>
            <div class="box-body">
                <p class="lead">{{ currentStep.description }}</p>

                <!-- AI Suggestion Panel -->
                {% if currentStep.ai_suggestion is defined and currentStep.ai_suggestion %}
                <div class="box box-solid box-success">
                    <div class="box-header with-border">
                        <h4 class="box-title">
                            <span class="glyphicon glyphicon-robot"></span>
                            AI Guidance
                        </h4>
                    </div>
                    <div class="box-body">
                        <p>{{ currentStep.ai_suggestion }}</p>
                    </div>
                </div>
                {% endif %}

                <!-- Step Form Fields -->
                {% if currentStep.fields is defined and currentStep.fields %}
                <div class="box box-solid box-default">
                    <div class="box-header with-border">
                        <h4 class="box-title">Configuration Options</h4>
                    </div>
                    <div class="box-body">
                        <form id="guide-step-form">
                            <input type="hidden" name="guide_id" value="{{ guide.id }}">
                            <input type="hidden" name="step_id" value="{{ currentStep.id }}">

                            {% for field in currentStep.fields %}
                            <div class="form-group">
                                {% if field.type == 'checkbox' %}
                                <div class="checkbox">
                                    <label>
                                        <input type="checkbox" name="{{ field.name }}"
                                               value="1" {% if field.default == '1' %}checked{% endif %}>
                                        {{ field.label }}
                                    </label>
                                </div>
                                {% elseif field.type == 'select' %}
                                <label for="{{ field.name }}">{{ field.label }}</label>
                                <select class="form-control" name="{{ field.name }}"
                                        id="{{ field.name }}">
                                    {% for option in field.options %}
                                    <option value="{{ option }}" {% if option == field.default %}selected{% endif %}>
                                        {{ option }}
                                    </option>
                                    {% endfor %}
                                </select>
                                {% else %}
                                <label for="{{ field.name }}">{{ field.label }}</label>
                                <input type="text" class="form-control" name="{{ field.name }}"
                                       id="{{ field.name }}" value="{{ field.default }}">
                                {% endif %}
                            </div>
                            {% endfor %}

                            <button type="submit" class="btn btn-primary">
                                Save & Continue
                            </button>
                        </form>
                    </div>
                </div>
                {% endif %}
            </div>
            <div class="box-footer">
                <small>
                    <span class="glyphicon glyphicon-info-sign"></span>
                    Your selections are saved locally and carried through the guide.
                </small>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('guide-step-form')?.addEventListener('submit', function(e) {
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);
    var data = {};
    formData.forEach(function(value, key) {
        data[key] = value;
    });

    fetch('/api/hermes/guide/save/{{ guide.id }}', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'data=' + encodeURIComponent(JSON.stringify(data))
    }).then(function(r) { return r.json(); })
      .then(function(result) {
          if (result.status === 'ok') {
              window.location.href = '?id={{ guide.id }}&step={{ currentStep.id }}&saved=1';
          } else {
              alert('Save failed: ' + (result.message || 'Unknown error'));
          }
      });
});
</script>
