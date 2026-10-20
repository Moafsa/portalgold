@extends('admin.layout.master')

@section('content')

{{-- Pending KYC Section --}}
@if($pending->count() > 0)
<div class="row" style="margin-bottom:20px;">
    <div class="col-md-12">
        <div class="panel panel-warning" style="border-radius:8px;">
            <div class="panel-heading" style="background:#e67e22;border-radius:8px 8px 0 0;">
                <h4 class="panel-title" style="color:#fff;margin:0;">
                    <i class="fa fa-hourglass-half"></i>
                    Aguardando Verificação Manual
                    <span class="label label-light" style="margin-left:8px;font-size:12px;background:#fff;color:#e67e22;">{{ $pending->count() }}</span>
                </h4>
            </div>
            <div class="panel-body" style="padding:0;">
                <table class="table table-condensed" style="margin:0;">
                    <thead style="background:#fef9f0;">
                        <tr>
                            <th>#</th>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>Selfie</th>
                            <th>Documento</th>
                            <th>Termos aceitos</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($pending as $p)
                    <tr>
                        <td>{{ $p->id }}</td>
                        <td>{{ $p->name ?: $p->username }}</td>
                        <td>{{ $p->email }}</td>
                        <td>
                            @if($p->selfie)
                            <a href="/assets/images/profile/{{ $p->selfie }}" target="_blank">
                                <img src="/assets/images/profile/{{ $p->selfie }}" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
                            </a>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        <td>
                            @if($p->identity)
                            <a href="/assets/images/profile/{{ $p->identity }}" target="_blank">
                                <img src="/assets/images/profile/{{ $p->identity }}" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
                            </a>
                            @else <span class="text-muted">—</span> @endif
                        </td>
                        <td style="font-size:11px;">{{ $p->terms_accepted_at ? \Carbon\Carbon::parse($p->terms_accepted_at)->format('d/m/Y H:i') : '—' }}</td>
                        <td>
                            <a href="{{ uroute('user.single', $p->username) }}" class="btn btn-xs btn-warning">
                                <i class="fa fa-check"></i> Verificar
                            </a>
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-inverse" style="border-radius:8px;">
            <div class="panel-heading" style="background:#1a2035;border-radius:8px 8px 0 0;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <h4 class="panel-title" style="color:#fff;margin:0;">
                        <i class="fa fa-check-circle" style="color:#27ae60;"></i>
                        Usuários Verificados
                        <span class="label label-success" style="margin-left:8px;font-size:12px;">{{ $users->total() }}</span>
                    </h4>
                    <a href="{{ uroute('users') }}" class="btn btn-sm btn-default">
                        <i class="fa fa-users"></i> Todos os Usuários
                    </a>
                </div>
            </div>
            <div class="panel-body" style="padding:0;">
                <table class="table table-striped table-condensed" style="margin:0;">
                    <thead style="background:#f8f9fc;">
                        <tr>
                            <th style="width:36px;">#</th>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>Documento</th>
                            <th>Endereço GOL</th>
                            <th>Saldo GOL</th>
                            <th>Carteira EVM</th>
                            <th>Saldo On-chain</th>
                            <th>Verificado em</th>
                            <th style="width:80px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                        <tr>
                            <td>{{ $user->id }}</td>
                            <td>
                                @if($user->photo)
                                <img src="{{ asset('assets/images/profile/' . $user->photo) }}"
                                     style="width:28px;height:28px;border-radius:50%;object-fit:cover;margin-right:6px;"
                                     onerror="this.style.display='none'">
                                @endif
                                {{ $user->name }}
                            </td>
                            <td>{{ $user->email }}</td>
                            <td>
                                @if($user->document_number)
                                <code style="font-size:12px;">{{ $user->document_number }}</code>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            {{-- Endereço GOL interno (tabela addresses) --}}
                            @php $primaryAddr = $user->address()->orderByDesc('balance')->first(); @endphp
                            <td>
                                @if($primaryAddr && $primaryAddr->address)
                                <code style="font-size:11px;">
                                    {{ substr($primaryAddr->address, 0, 8) }}…{{ substr($primaryAddr->address, -4) }}
                                </code>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            {{-- Saldo GOL --}}
                            <td>
                                @if($primaryAddr)
                                <span style="font-size:12px;font-weight:600;color:{{ $primaryAddr->balance > 0 ? '#27ae60' : '#999' }};">
                                    {{ number_format($primaryAddr->balance, 0, ',', '.') }}
                                </span>
                                @else
                                <span class="text-muted">0</span>
                                @endif
                            </td>
                            {{-- Carteira EVM externa (0x...) --}}
                            <td>
                                @if($user->wallet)
                                <code style="font-size:11px;" title="{{ $user->wallet }}">
                                    {{ substr($user->wallet, 0, 8) }}…{{ substr($user->wallet, -4) }}
                                </code>
                                @else
                                <span class="text-muted" title="Nenhuma carteira EVM vinculada">—</span>
                                @endif
                            </td>
                            {{-- Saldo FGOL on-chain --}}
                            <td>
                                @if($user->wallet)
                                    @php $oc = $onchain[strtolower($user->wallet)] ?? null; @endphp
                                    @if($oc !== null)
                                    <span style="font-size:12px;font-weight:600;color:{{ $oc > 0 ? '#27ae60' : '#999' }};">{{ number_format($oc, 2, ',', '.') }}</span>
                                    @else
                                    <span class="text-muted" title="Não foi possível consultar o RPC">n/d</span>
                                    @endif
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($user->updated_at)
                                <span style="font-size:12px;">{{ \Carbon\Carbon::parse($user->updated_at)->format('d/m/Y') }}</span>
                                @else
                                —
                                @endif
                            </td>
                            <td>
                                <a href="{{ uroute('user.single', ['user' => $user->id]) }}"
                                   class="btn btn-xs btn-primary" title="Ver perfil">
                                    <i class="fa fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted" style="padding:40px;">
                                <i class="fa fa-shield fa-2x" style="opacity:.3;display:block;margin-bottom:10px;"></i>
                                Nenhum usuário verificado ainda.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                <div style="padding:12px 16px;">{{ $users->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
