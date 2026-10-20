@extends('admin.layout.master')
@section('title', 'Nova Campanha de E-mail')

@section('content')

{{-- ── Cards de estatísticas ──────────────────────────────────────────── --}}
<div class="row" style="margin-bottom:10px;">
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div style="background:#3598dc;color:#fff;padding:16px 14px;border-radius:6px;margin-bottom:10px;">
            <div style="font-size:26px;font-weight:300;line-height:1;">{{ $totalUsers }}</div>
            <div style="font-size:12px;opacity:.85;">Total de usuários</div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div style="background:#32c5d2;color:#fff;padding:16px 14px;border-radius:6px;margin-bottom:10px;">
            <div style="font-size:26px;font-weight:300;line-height:1;">{{ $verifiedUsers }}</div>
            <div style="font-size:12px;opacity:.85;">KYC verificados</div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div style="background:#e7505a;color:#fff;padding:16px 14px;border-radius:6px;margin-bottom:10px;">
            <div style="font-size:26px;font-weight:300;line-height:1;">{{ $unverifiedUsers }}</div>
            <div style="font-size:12px;opacity:.85;">Não verificados</div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div style="background:#8E44AD;color:#fff;padding:16px 14px;border-radius:6px;margin-bottom:10px;">
            <div style="font-size:26px;font-weight:300;line-height:1;">{{ $withBalanceUsers }}</div>
            <div style="font-size:12px;opacity:.85;">Com saldo</div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div style="background:#f39c12;color:#fff;padding:16px 14px;border-radius:6px;margin-bottom:10px;">
            <div style="font-size:26px;font-weight:300;line-height:1;">{{ $emailVerifiedUsers }}</div>
            <div style="font-size:12px;opacity:.85;">E-mail confirmado</div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div style="background:#578ebe;color:#fff;padding:16px 14px;border-radius:6px;margin-bottom:10px;">
            <div style="font-size:26px;font-weight:300;line-height:1;">{{ $totalUsers - $withBalanceUsers }}</div>
            <div style="font-size:12px;opacity:.85;">Sem saldo</div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <div class="panel panel-inverse">
            <div class="panel-heading" style="background:#1a2035;">
                <h4 class="panel-title" style="color:#fff;">
                    <i class="fa fa-paper-plane" style="color:#f5a623;"></i> Nova Campanha de E-mail
                </h4>
            </div>
            <div class="panel-body">

                @if($errors->any())
                <div class="alert alert-danger">
                    <ul style="margin:0;padding-left:16px;">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
                @endif

                <div class="alert alert-info" style="font-size:13px;">
                    <i class="fa fa-info-circle"></i>
                    E-mails enviados em fila com rate limit, rastreamento de enviados/falhas e suporte a convite de agenda.<br>
                    Variáveis: <code>@{{name}}</code> <code>@{{email}}</code> <code>@{{username}}</code> &middot; convite de call: <code>@{{event_url}}</code> <code>@{{event_url_enc}}</code> <code>@{{event_title}}</code>
                </div>

                <form action="{{ uroute('admin.email.campaigns.store') }}" method="POST">
                    @csrf

                    {{-- Template --}}
                    @if($templates->count())
                    <div class="form-group">
                        <label><i class="fa fa-file-text-o"></i> Carregar template <small class="text-muted">(opcional)</small></label>
                        <select id="templateSelect" class="form-control" onchange="loadTemplate(this.value)">
                            <option value="">— selecione para pré-preencher —</option>
                            @foreach($templates as $tpl)
                            <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <hr style="margin:10px 0 16px;">
                    @endif

                    <div class="row">
                        <div class="col-md-7">
                            <div class="form-group">
                                <label>Assunto <span class="text-danger">*</span></label>
                                <input type="text" name="subject" id="fieldSubject" class="form-control"
                                       placeholder="Ex: Atualização importante da sua conta GOLDOFIR"
                                       value="{{ old('subject') }}" required>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-group">
                                <label>Segmento de destinatários</label>
                                <select name="target" id="targetSelect" class="form-control" onchange="onTargetChange(this.value)">
                                    <option value="all"           {{ old('target','all')=='all'?'selected':'' }}>Todos os usuários ({{ $totalUsers }})</option>
                                    <option value="email_verified"{{ old('target')=='email_verified'?'selected':'' }}>E-mail confirmado ({{ $emailVerifiedUsers }})</option>
                                    <option value="verified"      {{ old('target')=='verified'?'selected':'' }}>KYC verificados ({{ $verifiedUsers }})</option>
                                    <option value="unverified"    {{ old('target')=='unverified'?'selected':'' }}>Não verificados ({{ $unverifiedUsers }})</option>
                                    <option value="with_balance"  {{ old('target')=='with_balance'?'selected':'' }}>Com saldo > 0 ({{ $withBalanceUsers }})</option>
                                    <option value="without_balance"{{ old('target')=='without_balance'?'selected':'' }}>Sem saldo ({{ $totalUsers - $withBalanceUsers }})</option>
                                    <option value="specific"      {{ old('target')=='specific'?'selected':'' }}>E-mails específicos (informar abaixo)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- E-mails específicos --}}
                    <div id="specificEmailsBlock" style="{{ old('target')=='specific' ? '' : 'display:none;' }}">
                        <div class="form-group">
                            <label>E-mails destinatários <span class="text-danger">*</span></label>
                            <textarea name="specific_emails" id="specificEmails" class="form-control" rows="4"
                                      placeholder="joao@email.com, maria@email.com&#10;Um por linha ou separados por vírgula/ponto-e-vírgula">{{ old('specific_emails') }}</textarea>
                            <small class="text-muted">Separe por vírgula, ponto-e-vírgula ou linha. Se o e-mail pertencer a um usuário cadastrado, <code>@{{name}}</code> etc. serão preenchidos.</small>
                            <div id="emailCount" class="text-info" style="margin-top:4px;font-size:13px;"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Corpo do e-mail (HTML permitido) <span class="text-danger">*</span></label>
                        <textarea name="body" id="fieldBody" class="form-control" rows="14"
                                  placeholder="<h2>Olá, @{{name}}!</h2>..."
                                  required>{{ old('body') }}</textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Velocidade de envio (e-mails/hora)</label>
                                <input type="number" name="rate_hour" class="form-control"
                                       value="{{ old('rate_hour', 250) }}" min="1" max="1000"
                                       oninput="updateEstimate()" id="rateInput">
                                <small class="text-muted">Recomendado: 250/hora</small>
                            </div>
                        </div>
                        <div class="col-md-8" style="padding-top:30px;">
                            <div class="alert alert-warning" style="font-size:13px;">
                                <i class="fa fa-clock-o"></i>
                                Estimativa: <strong id="totalCount">{{ $totalUsers }}</strong> e-mails →
                                <strong id="timeLabel">{{ round($totalUsers / 250, 1) }} horas</strong>
                            </div>
                        </div>
                    </div>

                    {{-- ── Convite para Call/Evento ────────────────────────────────────── --}}
                    <div class="panel panel-default" style="margin-top:10px;border:2px dashed #ddd;">
                        <div class="panel-heading" style="background:#f9f9f9;cursor:pointer;" onclick="toggleEventSection()">
                            <h5 style="margin:0;font-size:14px;">
                                <input type="checkbox" name="is_event" id="isEventCheck" value="1"
                                       {{ old('is_event') ? 'checked' : '' }}
                                       onclick="event.stopPropagation();toggleEventSection()">
                                &nbsp;<i class="fa fa-video-camera" style="color:#5cb85c;"></i>
                                <strong>Esta campanha é um convite para call / videochamada</strong>
                                <small class="text-muted">(adiciona convite .ics na agenda do destinatário)</small>
                            </h5>
                        </div>
                        <div id="eventSection" style="{{ old('is_event') ? '' : 'display:none;' }} padding:15px;">
                            <div class="alert alert-success" style="font-size:12px;margin-bottom:12px;">
                                <i class="fa fa-calendar-check-o"></i>
                                No Gmail o destinatário verá botões <strong>Sim / Não / Talvez</strong> e o evento aparecerá automaticamente na agenda Google.
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Título do evento <span class="text-danger">*</span></label>
                                        <input type="text" name="event_title" class="form-control"
                                               placeholder="Ex: Reunião GolPay — Apresentação Q4"
                                               value="{{ old('event_title') }}">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Data e hora de início <span class="text-danger">*</span></label>
                                        <input type="datetime-local" name="event_at" class="form-control"
                                               value="{{ old('event_at') }}">
                                        <small class="text-muted">Horário local → convertido para UTC</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Duração (minutos) <span class="text-danger">*</span></label>
                                        <input type="number" name="event_duration_min" class="form-control"
                                               value="{{ old('event_duration_min', 60) }}" min="1" max="1440">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Link da videochamada <span class="text-danger">*</span></label>
                                <input type="url" name="event_url" class="form-control"
                                       placeholder="https://meet.google.com/xxx-xxxx-xxx  ou  https://zoom.us/j/..."
                                       value="{{ old('event_url') }}">
                                <small class="text-muted">Google Meet, Zoom, Teams ou qualquer plataforma. No corpo do e-mail use <code>@{{event_url}}</code> (ex.: <code>href=&quot;@{{event_url}}&quot;</code>) para inserir este link; <code>@{{event_title}}</code> insere o título.</small>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <div class="row">
                        <div class="col-md-6 col-md-offset-3">
                            <button type="submit" class="btn btn-warning btn-block btn-lg">
                                <i class="fa fa-paper-plane"></i> Criar campanha e colocar na fila
                            </button>
                            <a href="{{ uroute('admin.email.campaigns') }}" class="btn btn-default btn-block" style="margin-top:8px;">
                                Cancelar
                            </a>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<script>
var counts = {
    all:            {{ $totalUsers }},
    email_verified: {{ $emailVerifiedUsers }},
    verified:       {{ $verifiedUsers }},
    unverified:     {{ $unverifiedUsers }},
    with_balance:   {{ $withBalanceUsers }},
    without_balance:{{ $totalUsers - $withBalanceUsers }},
    specific: 0
};

function onTargetChange(target) {
    var specificBlock = document.getElementById('specificEmailsBlock');
    var specificTa    = document.getElementById('specificEmails');
    if (target === 'specific') {
        specificBlock.style.display = '';
        specificTa.required = true;
        document.getElementById('totalCount').textContent = countSpecificEmails();
    } else {
        specificBlock.style.display = 'none';
        specificTa.required = false;
        document.getElementById('totalCount').textContent = counts[target] || 0;
    }
    updateEstimate();
}

function countSpecificEmails() {
    var val   = document.getElementById('specificEmails').value;
    var parts = val.split(/[\s,;]+/).map(function(s){ return s.trim(); }).filter(Boolean);
    var valid = parts.filter(function(s){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s); });
    document.getElementById('emailCount').textContent = valid.length > 0 ? valid.length + ' e-mail(s) válido(s) detectado(s)' : '';
    return valid.length;
}

function updateEstimate() {
    var rate  = parseInt(document.getElementById('rateInput').value) || 250;
    var total = parseInt(document.getElementById('totalCount').textContent) || 0;
    var hours = total / rate;
    document.getElementById('timeLabel').textContent = hours < 1 ? Math.round(hours * 60) + ' min' : hours.toFixed(1) + ' horas';
}

function toggleEventSection() {
    var check   = document.getElementById('isEventCheck');
    var section = document.getElementById('eventSection');
    section.style.display = check.checked ? '' : 'none';
    ['event_title', 'event_at', 'event_duration_min', 'event_url'].forEach(function(n) {
        var el = document.querySelector('[name="' + n + '"]');
        if (el) el.required = check.checked;
    });
}

function loadTemplate(id) {
    if (!id) return;
    var url = '{{ uroute("admin.email.template", ["id" => "__ID__"]) }}'.replace('__ID__', id);
    fetch(url).then(function(r){ return r.json(); }).then(function(data) {
        if (data.subject) document.getElementById('fieldSubject').value = data.subject;
        if (data.content) document.getElementById('fieldBody').value = data.content;
    });
}

document.getElementById('specificEmails').addEventListener('input', function() {
    if (document.getElementById('targetSelect').value === 'specific') {
        document.getElementById('totalCount').textContent = countSpecificEmails();
        updateEstimate();
    }
});

onTargetChange(document.getElementById('targetSelect').value);
toggleEventSection();
</script>
@endsection
