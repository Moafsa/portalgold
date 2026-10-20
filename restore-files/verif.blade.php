@extends('layouts.user')

@section('content')

{{-- ===== ALREADY VERIFIED ===== --}}
@if($profile->verified == 1)
<div class="row">
    <div class="col-md-8 col-md-offset-2">
        <div class="panel panel-inverse" style="margin-top:20px;">
            <div class="panel-body text-center" style="padding:40px;">
                <i class="fa fa-check-circle fa-4x" style="color:#27ae60;margin-bottom:20px;"></i>
                <h3 style="color:#1a2035;font-weight:700;">Conta Verificada!</h3>
                <p class="text-muted">Sua identidade foi confirmada. Você tem acesso completo à plataforma GOLDOFIR.</p>

                @if($profile->wallet)

                @if(!$profile->cdp_user_id || !$delegationOk)
                {{-- Delegação pendente (confirmada na Coinbase, não só no banco): usuário tem carteira mas nunca autorizou acesso server-side --}}
                <div style="margin:16px auto;max-width:500px;background:#fff3e0;border:1px solid #ffcc80;border-radius:10px;padding:18px;" id="delegation-box">
                    <div style="font-size:13px;font-weight:700;color:#e65100;margin-bottom:6px;">
                        <i class="fa fa-exclamation-triangle"></i> Autorização pendente
                    </div>
                    <p style="font-size:12px;color:#555;margin:0 0 10px;">
                        Para que a plataforma possa gerenciar sua carteira automaticamente, confirme sua identidade uma vez com o código enviado para seu e-mail.
                    </p>
                    <div id="del-otp-area" style="display:none;margin-bottom:10px;">
                        <input type="text" id="del-otp-input" class="form-control"
                               placeholder="Código OTP do e-mail" maxlength="8"
                               style="text-align:center;font-size:18px;letter-spacing:6px;font-weight:700;margin-bottom:8px;">
                        <button onclick="delConfirmOtp()" id="del-otp-btn" class="btn btn-warning btn-block btn-sm">
                            <i class="fa fa-check"></i> Confirmar e autorizar
                        </button>
                    </div>
                    <button onclick="delSendOtp()" id="del-send-btn" class="btn btn-warning btn-block btn-sm">
                        <i class="fa fa-envelope"></i> Receber código de autorização
                    </button>
                    <div id="del-result" style="display:none;margin-top:8px;font-size:12px;"></div>
                </div>
                @endif

                <script src="/assets/cdp-wallet.bundle.js"></script>
                <script>
                const USER_WALLET   = '{{ $profile->wallet }}';
                const CDP_PROJECT   = '{{ \App\General::first()->cdp_project_id ?? "" }}';
                const USER_EMAIL    = '{{ $profile->email }}';
                const CSRF_TOKEN    = '{{ csrf_token() }}';
                const SAVE_URL      = '{{ uroute("profile.saveWallet") }}';

                if (window.cdpWallet) window.cdpWallet.initialize({ projectId: CDP_PROJECT });

                let delFlowId = null;

                window.delSendOtp = async function() {
                    const btn = document.getElementById('del-send-btn');
                    const res = document.getElementById('del-result');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa fa-spin fa-spinner"></i> Enviando…';
                    res.style.display = 'none';
                    try {
                        const r = await window.cdpWallet.signInWithEmail({ email: USER_EMAIL });
                        delFlowId = r.flowId;
                        document.getElementById('del-otp-area').style.display = 'block';
                        btn.style.display = 'none';
                        document.getElementById('del-otp-input').focus();
                    } catch(e) {
                        const msg = e.message || '';
                        if (msg.toLowerCase().includes('already linked') || msg.toLowerCase().includes('already exists')) {
                            // Email already linked — OTP was still sent, or try with null flowId
                            delFlowId = null;
                            document.getElementById('del-otp-area').style.display = 'block';
                            btn.style.display = 'none';
                            document.getElementById('del-otp-input').focus();
                        } else {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fa fa-envelope"></i> Receber código de autorização';
                            res.style.cssText = 'display:block;color:#e74c3c;font-size:12px;';
                            res.innerHTML = '<i class="fa fa-warning"></i> Erro: ' + msg + ' | projectId: ' + CDP_PROJECT;
                        }
                    }
                };

                window.delConfirmOtp = async function() {
                    const otp = document.getElementById('del-otp-input').value.trim();
                    if (!otp) { alert('Digite o código.'); return; }
                    const btn = document.getElementById('del-otp-btn');
                    const res = document.getElementById('del-result');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa fa-spin fa-spinner"></i> Autorizando…';
                    try {
                        const otpResult = await window.cdpWallet.verifyEmailOTP({ flowId: delFlowId, otp });

                        // userId comes directly from verifyEmailOTP result
                        let cdpUserId = otpResult?.user?.userId || null;

                        let delegationExpiresAt = null;
                        try {
                            const exp = new Date(); exp.setFullYear(exp.getFullYear() + 1);
                            const d = await window.cdpWallet.createDelegationForAccount({
                                address: USER_WALLET, expiresAt: exp.toISOString()
                            });
                            delegationExpiresAt = d?.expiresAt || exp.toISOString();
                        } catch(de) { console.warn('delegation:', de.message); }

                        if (!cdpUserId) throw new Error('CDP userId não retornado. OTP pode ter expirado — tente novamente.');

                        // Save cdp_user_id + delegation
                        const saveResp = await fetch(SAVE_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                            body: JSON.stringify({ wallet_address: USER_WALLET, cdp_user_id: cdpUserId, delegation_expires_at: delegationExpiresAt, update_only: true })
                        });
                        const saveData = await saveResp.json();
                        if (!saveData.success) throw new Error(saveData.message || 'Erro ao salvar autorização.');
                        if (saveData.delegated === false) throw new Error(saveData.message || 'A Coinbase ainda não confirmou a autorização. Tente novamente.');

                        res.style.cssText = 'display:block;color:#27ae60;font-size:12px;';
                        res.innerHTML = '<i class="fa fa-check-circle"></i> Autorização concluída! userId: ' + cdpUserId.substring(0,8) + '…';
                        document.getElementById('delegation-box').style.background = '#f0faf4';
                        document.getElementById('delegation-box').style.borderColor = '#b2dfdb';
                        setTimeout(() => location.reload(), 2000);
                    } catch(e) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa fa-check"></i> Confirmar e autorizar';
                        res.style.cssText = 'display:block;color:#e74c3c;font-size:12px;';
                        res.innerHTML = '<i class="fa fa-warning"></i> ' + e.message;
                    }
                };

                </script>
                @else
                {{-- CDP Embedded Wallet: criação client-side via email+OTP --}}
                @php $cdpProjectId = \App\General::first()->cdp_project_id ?? ''; @endphp


                <div id="wallet-step-init" style="margin:24px auto;max-width:460px;background:#fff8e1;border:1px solid #ffe082;border-radius:12px;padding:24px;text-align:center;">
                    <i class="fa fa-cog fa-spin fa-2x" style="color:#f5a623;margin-bottom:12px;display:block;"></i>
                    <strong style="font-size:15px;color:#1a2035;">Preparando sua Carteira GOL…</strong>
                    <p style="font-size:13px;color:#888;margin:8px 0 0;">Aguarde, conectando com a Coinbase.</p>
                </div>

                <div id="wallet-step-otp" style="display:none;margin:24px auto;max-width:460px;background:#fff8e1;border:1px solid #ffe082;border-radius:12px;padding:24px;">
                    <div style="text-align:center;margin-bottom:16px;">
                        <i class="fa fa-envelope fa-2x" style="color:#f5a623;margin-bottom:8px;display:block;"></i>
                        <strong style="font-size:15px;color:#1a2035;">Código enviado para seu e-mail</strong>
                        <p style="font-size:13px;color:#888;margin:6px 0 0;">A Coinbase enviou um código para <strong id="wallet-email-shown"></strong>. Digite abaixo:</p>
                    </div>
                    <div class="form-group">
                        <input type="text" id="wallet-otp-input" class="form-control input-lg"
                               placeholder="123456" maxlength="8"
                               style="text-align:center;font-size:22px;letter-spacing:8px;font-weight:700;">
                    </div>
                    <button onclick="walletConfirmOtp()" id="wallet-otp-btn" class="btn btn-warning btn-block btn-lg">
                        <i class="fa fa-check-circle"></i> Confirmar e criar carteira
                    </button>
                    <div style="text-align:center;margin-top:8px;">
                        <button onclick="walletInit()" class="btn btn-link btn-sm text-muted">Reenviar código</button>
                    </div>
                </div>

                <div id="wallet-step-error" style="display:none;margin:24px auto;max-width:460px;">
                    <div class="alert alert-danger" style="font-size:13px;" id="wallet-error-msg"></div>
                    <button onclick="walletInit()" class="btn btn-warning btn-block">
                        <i class="fa fa-refresh"></i> Tentar novamente
                    </button>
                </div>

                <script src="/assets/cdp-wallet.bundle.js"></script>
                <script>
                const { initialize, signInWithEmail, verifyEmailOTP, createEvmEoaAccount } = window.cdpWallet;

                const CDP_PROJECT_ID = '{{ $cdpProjectId }}';
                const USER_EMAIL     = '{{ $profile->email }}';
                const SAVE_URL       = '{{ uroute("profile.saveWallet") }}';
                const CSRF_TOKEN     = '{{ csrf_token() }}';

                const cdpReady = Promise.resolve(initialize({ projectId: CDP_PROJECT_ID }));

                let currentFlowId = null;

                function show(id) {
                    ['wallet-step-init','wallet-step-otp','wallet-step-error']
                        .forEach(s => document.getElementById(s).style.display = (s === id ? 'block' : 'none'));
                }
                function showError(msg) {
                    document.getElementById('wallet-error-msg').innerHTML =
                        '<i class="fa fa-warning"></i> ' + (msg || 'Erro desconhecido. Tente novamente.');
                    show('wallet-step-error');
                }

                window.walletInit = async function() {
                    show('wallet-step-init');
                    try {
                        const res = await signInWithEmail({ email: USER_EMAIL });
                        currentFlowId = res.flowId;
                        document.getElementById('wallet-email-shown').textContent = USER_EMAIL;
                        show('wallet-step-otp');
                        document.getElementById('wallet-otp-input').focus();
                    } catch(e) {
                        const msg = e.message || '';
                        // Email já vinculado na CDP — ainda pode autenticar com OTP para recuperar a carteira
                        if (msg.toLowerCase().includes('already linked') || msg.toLowerCase().includes('already exists')) {
                            currentFlowId = null;
                            document.getElementById('wallet-email-shown').textContent = USER_EMAIL;
                            show('wallet-step-otp');
                            document.getElementById('wallet-otp-input').focus();
                        } else {
                            showError('Falha ao enviar código: ' + msg);
                        }
                    }
                };

                // Everything after Coinbase sign-in: reuse/create the wallet, authorize the platform, save on the server
                async function completeWallet(cdpUser) {
        // userId comes directly from verifyEmailOTP result
        let cdpUserId = cdpUser?.userId || null;

        let address = null;
        // Reuse the wallet this Coinbase user already has (e.g. a previous attempt that failed to save) — never create a second one
        const existingAcc = cdpUser?.evmAccounts?.[0]
            || (window.cdpWallet.getCurrentUserSync && window.cdpWallet.getCurrentUserSync()?.evmAccounts?.[0]);
        if (existingAcc) {
            address = typeof existingAcc === 'string' ? existingAcc : (existingAcc.address || null);
        }
        try {
            if (!address) {
                const account = await createEvmEoaAccount();
                address = account.address || (typeof account === 'string' ? account : null);
            }
        } catch(createErr) {
            // Conta já existe — tenta recuperar via listEvmAccounts se disponível
            const listFn = window.cdpWallet.listEvmAccounts || window.cdpWallet.getEvmAccounts;
            if (listFn) {
                const accounts = await listFn();
                address = Array.isArray(accounts) ? (accounts[0]?.address || accounts[0]) : null;
            }
            if (!address) throw createErr;
        }
        if (!address) throw new Error('Endereço não retornado pela Coinbase.');

        // Cria delegação: autoriza o servidor a assinar tx em nome do usuário por 1 ano
        let delegationExpiresAt = null;
        try {
            const expiry = new Date();
            expiry.setFullYear(expiry.getFullYear() + 1);
            const delResult = await window.cdpWallet.createDelegationForAccount({
                address: address,
                expiresAt: expiry.toISOString(),
            });
            delegationExpiresAt = delResult?.expiresAt || expiry.toISOString();
        } catch(delErr) {
            // Delegação falhou — não bloqueia o salvamento da carteira
            console.warn('createDelegationForAccount falhou:', delErr.message);
        }

        const resp = await fetch(SAVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body: JSON.stringify({ wallet_address: address, cdp_user_id: cdpUserId, delegation_expires_at: delegationExpiresAt })
        });
        const data = await resp.json();
        if (data.success) {
            location.reload();
        } else {
            throw new Error(data.message || 'Erro ao salvar carteira.');
        }
                }

                window.walletConfirmOtp = async function() {
                    const otp = document.getElementById('wallet-otp-input').value.trim();
                    if (!otp) { alert('Digite o código recebido no e-mail.'); return; }
                    const btn = document.getElementById('wallet-otp-btn');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa fa-spin fa-spinner"></i> Criando carteira…';
                    try {
                        const otpRes = await verifyEmailOTP({ flowId: currentFlowId, otp });
                        await completeWallet(otpRes?.user);
                    } catch(e) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa fa-check-circle"></i> Confirmar e criar carteira';
                        showError(e.message);
                    }
                };

                // Start automatically on page load. If this browser still has a valid Coinbase session for the SAME
                // e-mail (e.g. a first attempt that failed to save), finish without asking for another code.
                (async function bootstrap() {
                    try {
                        await cdpReady;
                        const cur = window.cdpWallet.getCurrentUserSync && window.cdpWallet.getCurrentUserSync();
                        const curEmail = ((cur && cur.authenticationMethods && cur.authenticationMethods.email && cur.authenticationMethods.email.email) || '').toLowerCase();
                        if (cur && cur.userId && curEmail === USER_EMAIL.toLowerCase() && (cur.evmAccounts || []).length) {
                            await completeWallet(cur);
                            return;
                        }
                    } catch (e) {
                        console.warn('Sessão Coinbase não reutilizável, pedindo código:', e && e.message);
                    }
                    walletInit();
                })();
                </script>
                @endif

                <div style="margin-top:24px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                    <a href="{{ uroute('home') }}" class="btn btn-warning btn-lg"><i class="fa fa-home"></i> Ir para o Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ===== ONBOARDING GAMIFICADO ===== --}}
@else
<style>
/* ── Onboarding Layout ── */
.kyc-wrapper {
    max-width: 680px;
    margin: 24px auto;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
/* ── Stepper ── */
.kyc-stepper {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 32px;
    gap: 0;
}
.kyc-step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    flex: 1;
}
.kyc-step-circle {
    width: 38px; height: 38px;
    border-radius: 50%;
    background: #e0e0e0;
    color: #999;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700;
    transition: all .3s;
    position: relative; z-index: 1;
    border: 3px solid #e0e0e0;
}
.kyc-step-item.active .kyc-step-circle {
    background: #1a2035; color: #fff; border-color: #f5a623;
    box-shadow: 0 0 0 4px rgba(245,166,35,.2);
}
.kyc-step-item.done .kyc-step-circle {
    background: #27ae60; color: #fff; border-color: #27ae60;
}
.kyc-step-label {
    font-size: 10px; color: #999; margin-top: 5px;
    text-align: center; white-space: nowrap;
}
.kyc-step-item.active .kyc-step-label,
.kyc-step-item.done .kyc-step-label { color: #1a2035; font-weight: 600; }
.kyc-step-line {
    flex: 1; height: 2px; background: #e0e0e0;
    margin: 0 -2px; margin-bottom: 22px;
    transition: background .3s;
    max-width: 60px;
}
.kyc-step-line.done { background: #27ae60; }

/* ── Card ── */
.kyc-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,.1);
    overflow: hidden;
}
.kyc-card-header {
    background: linear-gradient(135deg, #1a2035, #0d2647);
    padding: 24px 28px;
    color: #fff;
}
.kyc-card-header .step-badge {
    display: inline-block;
    background: rgba(245,166,35,.2);
    color: #f5a623;
    font-size: 11px; font-weight: 700;
    padding: 3px 10px; border-radius: 20px;
    margin-bottom: 8px;
}
.kyc-card-header h3 { margin: 0 0 6px; font-size: 20px; font-weight: 700; }
.kyc-card-header p  { margin: 0; color: rgba(255,255,255,.7); font-size: 13px; }
.kyc-card-body { padding: 28px; }

/* ── Progress bar ── */
.kyc-progress-bar {
    height: 4px; background: #e0e0e0;
    border-radius: 2px; overflow: hidden;
    margin-bottom: 4px;
}
.kyc-progress-fill {
    height: 100%; background: linear-gradient(90deg, #f5a623, #e89b00);
    border-radius: 2px; transition: width .4s ease;
}

/* ── Upload zones ── */
.kyc-upload-zone {
    border: 2px dashed #d0d8e8;
    border-radius: 12px;
    padding: 32px 20px;
    text-align: center;
    cursor: pointer;
    transition: all .2s;
    background: #f8fafc;
    position: relative;
}
.kyc-upload-zone:hover { border-color: #f5a623; background: #fffdf5; }
.kyc-upload-zone.has-file { border-color: #27ae60; border-style: solid; background: #f0fff4; }
.kyc-upload-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.kyc-upload-zone .preview-img {
    width: 100%; max-height: 200px; object-fit: contain;
    border-radius: 8px; margin-top: 10px; display: none;
}

/* ── Form inputs ── */
.kyc-label { font-size: 12px; font-weight: 700; color: #444; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; display: block; }
.kyc-input {
    width: 100%; padding: 11px 14px;
    border: 1px solid #d0d8e8; border-radius: 8px;
    font-size: 14px; color: #1a2035;
    transition: border-color .2s;
    background: #fff;
}
.kyc-input:focus { border-color: #f5a623; outline: none; box-shadow: 0 0 0 3px rgba(245,166,35,.1); }
.kyc-form-group { margin-bottom: 18px; }

/* ── Terms box ── */
.kyc-terms-box {
    border: 1px solid #d0d8e8; border-radius: 10px;
    height: 220px; overflow-y: auto; padding: 16px;
    font-size: 13px; line-height: 1.6; color: #444;
    background: #f8fafc; margin-bottom: 16px;
}
.kyc-terms-check {
    display: flex; align-items: center; gap: 10px;
    padding: 14px; border-radius: 8px;
    border: 2px solid #d0d8e8; cursor: pointer;
    transition: all .2s;
}
.kyc-terms-check.accepted { border-color: #27ae60; background: #f0fff4; }
.kyc-terms-check input { width: 18px; height: 18px; cursor: pointer; }
.kyc-terms-check label { cursor: pointer; font-size: 13px; font-weight: 600; color: #1a2035; }

/* ── AI Processing ── */
.kyc-processing {
    text-align: center; padding: 48px 20px;
    display: none;
}
.kyc-spinner {
    width: 64px; height: 64px; border-radius: 50%;
    border: 5px solid #e0e0e0;
    border-top-color: #f5a623;
    animation: spin .8s linear infinite;
    margin: 0 auto 20px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Buttons ── */
.kyc-btn-next {
    width: 100%; padding: 13px;
    background: linear-gradient(135deg, #f5a623, #e89b00);
    color: #1a2035; font-weight: 700; font-size: 15px;
    border: none; border-radius: 10px; cursor: pointer;
    transition: all .2s; margin-top: 8px;
}
.kyc-btn-next:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(245,166,35,.4); }
.kyc-btn-next:disabled { opacity: .6; cursor: default; transform: none; }
.kyc-btn-back {
    background: none; border: 1px solid #d0d8e8;
    color: #666; padding: 10px 20px; border-radius: 8px;
    cursor: pointer; font-size: 14px;
}
.kyc-btn-back:hover { border-color: #1a2035; color: #1a2035; }

/* ── Step panels ── */
.kyc-step-panel { display: none; animation: fadeIn .3s ease; }
.kyc-step-panel.active { display: block; }
@keyframes fadeIn { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: none; } }

/* ── Alerts ── */
.kyc-alert { padding: 12px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
.kyc-alert-warning { background: #fff8e6; border: 1px solid #f5a623; color: #b07800; }
.kyc-alert-info    { background: #e8f4fd; border: 1px solid #2196f3; color: #1565c0; }

/* ── Responsive ── */
@media (max-width: 600px) {
    .kyc-step-label { display: none; }
    .kyc-card-body { padding: 18px; }
}
</style>

@if(session('success'))
<div style="max-width:680px;margin:12px auto 0;">
    <div class="alert alert-success"><i class="fa fa-check-circle"></i> {{ session('success') }}</div>
</div>
@endif
@if(session('alert'))
<div style="max-width:680px;margin:12px auto 0;">
    <div class="alert alert-danger"><i class="fa fa-times-circle"></i> {{ session('alert') }}</div>
</div>
@endif
@if($profile->selfie_verified == 2)
<div style="max-width:680px;margin:8px auto 0;">
    <div class="alert alert-danger" style="border-left:4px solid #e74c3c;">
        <i class="fa fa-times-circle"></i> <strong>Selfie rejeitada.</strong>
        @if($profile->kyc_ai_result)@php $air=json_decode($profile->kyc_ai_result,true);@endphp
        {{ $air['selfie_reason'] ?? 'Por favor, envie uma nova selfie com rosto visível.' }}
        @else Por favor, envie uma nova selfie com rosto visível. @endif
    </div>
</div>
@endif
@if($profile->identity_verified == 2)
<div style="max-width:680px;margin:8px auto 0;">
    <div class="alert alert-danger" style="border-left:4px solid #e74c3c;">
        <i class="fa fa-times-circle"></i> <strong>Documento rejeitado.</strong>
        @if($profile->kyc_ai_result)@php $air2=json_decode($profile->kyc_ai_result,true);@endphp
        {{ $air2['identity_reason'] ?? 'Por favor, envie uma foto mais clara do documento.' }}
        @else Por favor, envie uma foto mais clara do documento. @endif
    </div>
</div>
@endif

<div class="kyc-wrapper">

    {{-- Progress bar --}}
    <div style="margin-bottom:20px;">
        <div class="kyc-progress-bar"><div class="kyc-progress-fill" id="kycProgressFill" style="width:16%;"></div></div>
        <small style="color:#999;font-size:11px;">Passo <span id="kycStepNum">1</span> de 6</small>
    </div>

    {{-- Stepper --}}
    <div class="kyc-stepper" id="kycStepper">
        <div class="kyc-step-item active" data-step="1">
            <div class="kyc-step-circle"><i class="fa fa-user"></i></div>
            <span class="kyc-step-label">Início</span>
        </div>
        <div class="kyc-step-line"></div>
        <div class="kyc-step-item" data-step="2">
            <div class="kyc-step-circle"><i class="fa fa-camera"></i></div>
            <span class="kyc-step-label">Selfie</span>
        </div>
        <div class="kyc-step-line"></div>
        <div class="kyc-step-item" data-step="3">
            <div class="kyc-step-circle"><i class="fa fa-id-card"></i></div>
            <span class="kyc-step-label">Documento</span>
        </div>
        <div class="kyc-step-line"></div>
        <div class="kyc-step-item" data-step="4">
            <div class="kyc-step-circle"><i class="fa fa-pencil"></i></div>
            <span class="kyc-step-label">Dados</span>
        </div>
        <div class="kyc-step-line"></div>
        <div class="kyc-step-item" data-step="5">
            <div class="kyc-step-circle"><i class="fa fa-map-marker"></i></div>
            <span class="kyc-step-label">Endereço</span>
        </div>
        <div class="kyc-step-line"></div>
        <div class="kyc-step-item" data-step="6">
            <div class="kyc-step-circle"><i class="fa fa-file-text"></i></div>
            <span class="kyc-step-label">Contrato</span>
        </div>
    </div>

    {{-- Main card --}}
    <div class="kyc-card" id="kyc-card-anchor">
        <form id="kycForm" action="{{ uroute('profile.updateverification') }}" method="POST" enctype="multipart/form-data">
            @csrf

            {{-- STEP 1: Welcome --}}
            <div class="kyc-step-panel active" id="panel-1">
                <div class="kyc-card-header">
                    <span class="step-badge">Passo 1 de 6</span>
                    <h3><i class="fa fa-hand-o-right" style="color:#f5a623;margin-right:8px;"></i>Bem-vindo ao GOLDOFIR!</h3>
                    <p>Vamos verificar sua identidade em apenas alguns passos.</p>
                </div>
                <div class="kyc-card-body">
                    <div style="text-align:center;padding:10px 0 20px;">
                        <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#1a2035,#0052ff);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                            <i class="fa fa-shield" style="font-size:36px;color:#f5a623;"></i>
                        </div>
                        <h4 style="color:#1a2035;font-weight:700;margin-bottom:8px;">Verificação KYC — Know Your Customer</h4>
                        <p style="color:#666;font-size:14px;max-width:420px;margin:0 auto;">
                            Para proteger você e a comunidade, precisamos confirmar sua identidade.
                            O processo leva menos de <strong>3 minutos</strong>.
                        </p>
                    </div>

                    <div style="display:flex;gap:12px;margin:20px 0;flex-wrap:wrap;">
                        <div style="flex:1;min-width:140px;background:#f8fafc;border-radius:10px;padding:14px;text-align:center;">
                            <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#1a2035,#2d4a8a);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;"><i class="fa fa-camera" style="font-size:18px;color:#f5a623;"></i></div>
                            <div style="font-size:12px;font-weight:700;color:#1a2035;">Selfie</div>
                            <div style="font-size:11px;color:#888;">Foto do seu rosto</div>
                        </div>
                        <div style="flex:1;min-width:140px;background:#f8fafc;border-radius:10px;padding:14px;text-align:center;">
                            <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#1a2035,#2d4a8a);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;"><i class="fa fa-id-card" style="font-size:18px;color:#f5a623;"></i></div>
                            <div style="font-size:12px;font-weight:700;color:#1a2035;">Documento</div>
                            <div style="font-size:11px;color:#888;">RG, CNH ou Passaporte</div>
                        </div>
                        <div style="flex:1;min-width:140px;background:#f8fafc;border-radius:10px;padding:14px;text-align:center;">
                            <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#1a2035,#2d4a8a);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;"><i class="fa fa-check-circle" style="font-size:18px;color:#f5a623;"></i></div>
                            <div style="font-size:12px;font-weight:700;color:#1a2035;">IA verifica</div>
                            <div style="font-size:11px;color:#888;">Em segundos</div>
                        </div>
                    </div>

                    <div class="kyc-alert kyc-alert-info">
                        <i class="fa fa-lock"></i>
                        <strong>Seus dados estão seguros.</strong> Imagens são usadas apenas para verificação e armazenadas com criptografia.
                    </div>

                    <button type="button" class="kyc-btn-next" onclick="kycNext()">
                        Começar verificação <i class="fa fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            {{-- STEP 2: Selfie --}}
            <div class="kyc-step-panel" id="panel-2">
                <div class="kyc-card-header">
                    <span class="step-badge">Passo 2 de 6</span>
                    <h3><i class="fa fa-camera" style="color:#f5a623;margin-right:8px;"></i>Sua Selfie</h3>
                    <p>Tire uma foto clara do seu rosto para confirmar que é você.</p>
                </div>
                <div class="kyc-card-body">
                    @if($profile->selfie_verified == 1)
                    <div class="kyc-alert" style="background:#f0fff4;border:1px solid #27ae60;color:#155724;margin-bottom:12px;">
                        <i class="fa fa-check-circle"></i> Selfie aprovada. Só substitua se o rosto não coincide com o documento.
                    </div>
                    @endif
                    {{-- File input always rendered so re-upload is always possible --}}
                    <div class="kyc-upload-zone" id="selfieZone" onclick="document.getElementById('selfie').click()">
                        <input type="file" name="selfie" id="selfie" accept="image/*" onclick="event.stopPropagation();" onchange="previewImg(this,'selfiePreview','selfieZone','selfieIcon')">
                        <div id="selfieIcon">
                            @if($profile->selfie)
                            <img src="{{ asset('assets/images/profile/' . $profile->selfie) }}" style="width:100px;height:100px;object-fit:cover;border-radius:50%;margin-bottom:10px;">
                            <div style="font-size:13px;color:#888;">Clique para trocar a selfie</div>
                            @else
                            <i class="fa fa-camera fa-3x" style="color:#ccc;margin-bottom:12px;"></i>
                            <div style="font-weight:700;color:#1a2035;margin-bottom:4px;">Toque para tirar selfie</div>
                            <div style="font-size:12px;color:#888;">JPG, PNG · máx 4MB</div>
                            @endif
                        </div>
                        <img id="selfiePreview" class="preview-img">
                    </div>

                    <div class="kyc-alert kyc-alert-warning" style="margin-top:16px;">
                        <i class="fa fa-lightbulb-o"></i>
                        <strong>Dicas:</strong> Boa iluminação, olhos abertos, sem óculos escuros, sem filtros.
                    </div>

                    <div style="display:flex;gap:10px;margin-top:16px;">
                        <button type="button" class="kyc-btn-back" onclick="kycBack()"><i class="fa fa-arrow-left"></i> Voltar</button>
                        <button type="button" class="kyc-btn-next" style="flex:1;" id="selfieNextBtn"
                            onclick="kycNext()">
                            @if($profile->selfie_verified == 1) Próximo <i class="fa fa-arrow-right"></i>
                            @else Continuar <i class="fa fa-arrow-right"></i>
                            @endif
                        </button>
                    </div>
                </div>
            </div>

            {{-- STEP 3: Document --}}
            <div class="kyc-step-panel" id="panel-3">
                <div class="kyc-card-header">
                    <span class="step-badge">Passo 3 de 6</span>
                    <h3><i class="fa fa-id-card" style="color:#f5a623;margin-right:8px;"></i>Documento de Identidade</h3>
                    <p>Foto do seu RG, CNH ou Passaporte com foto e número visíveis.</p>
                </div>
                <div class="kyc-card-body">
                    @if($profile->identity_verified == 1)
                    <div class="kyc-alert" style="background:#f0fff4;border:1px solid #27ae60;color:#155724;">
                        <i class="fa fa-check-circle"></i> Documento já verificado!
                    </div>
                    <img src="{{ asset('assets/images/profile/' . $profile->identity) }}" style="width:100%;max-height:180px;object-fit:contain;border-radius:8px;margin-bottom:16px;">
                    @else
                    <div class="kyc-upload-zone" id="identityZone" onclick="document.getElementById('identity').click()">
                        <input type="file" name="identity" id="identity" accept="image/*,application/pdf,.pdf" onclick="event.stopPropagation();" onchange="previewImg(this,'identityPreview','identityZone','identityIcon')">
                        <div id="identityIcon">
                            @if($profile->identity)
                            <img src="{{ asset('assets/images/profile/' . $profile->identity) }}" style="max-height:150px;max-width:100%;border-radius:8px;object-fit:contain;margin-bottom:8px;">
                            <div style="font-size:13px;color:#888;">Clique para trocar o documento</div>
                            @else
                            <i class="fa fa-id-card fa-3x" style="color:#ccc;margin-bottom:12px;"></i>
                            <div style="font-weight:700;color:#1a2035;margin-bottom:4px;">Envie a foto do documento</div>
                            <div style="font-size:12px;color:#888;">JPG, PNG ou PDF · máx 4MB</div>
                            @endif
                        </div>
                        <img id="identityPreview" class="preview-img">
                    </div>
                    @endif

                    @if($profile->identity_verified == 2)
                    <div class="kyc-alert" style="background:#fdf0f0;border:1px solid #e74c3c;color:#721c24;margin-top:16px;">
                        <i class="fa fa-times-circle"></i> <strong>Documento anterior rejeitado.</strong> Envie uma nova foto mais clara.
                    </div>
                    @else
                    <div class="kyc-alert kyc-alert-warning" style="margin-top:16px;">
                        <i class="fa fa-lightbulb-o"></i>
                        <strong>Dicas:</strong> Documento plano, iluminação boa, todos os dados visíveis. Não cubra número ou foto.
                    </div>
                    @endif

                    <div style="margin-top:16px;">
                        @if($profile->identity_verified == 2)
                        {{-- REJECTED: show submit button directly --}}
                        <div class="alert alert-info" style="font-size:13px;margin-bottom:10px;">
                            <i class="fa fa-info-circle"></i> Selecione um novo documento acima, depois clique em <strong>Reenviar</strong>.
                        </div>
                        <div style="display:flex;gap:10px;">
                            <button type="button" class="kyc-btn-back" onclick="kycBack()"><i class="fa fa-arrow-left"></i> Voltar</button>
                            <button type="button" class="kyc-btn-next" style="flex:1;background:#e74c3c;" id="identityNextBtn" onclick="kycResubmitDoc()">
                                <i class="fa fa-refresh"></i> Reenviar documento para verificação
                            </button>
                        </div>
                        @else
                        <div style="display:flex;gap:10px;">
                            <button type="button" class="kyc-btn-back" onclick="kycBack()"><i class="fa fa-arrow-left"></i> Voltar</button>
                            <button type="button" class="kyc-btn-next" style="flex:1;" id="identityNextBtn"
                                onclick="kycNext()">
                                @if($profile->identity_verified == 1) Próximo <i class="fa fa-arrow-right"></i>
                                @else Continuar <i class="fa fa-arrow-right"></i>
                                @endif
                            </button>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- STEP 4: Personal Info --}}
            <div class="kyc-step-panel" id="panel-4">
                <div class="kyc-card-header">
                    <span class="step-badge">Passo 4 de 6</span>
                    <h3><i class="fa fa-pencil-square-o" style="color:#f5a623;margin-right:8px;"></i>Seus Dados Pessoais</h3>
                    <p>Confirme seu nome completo e número do documento.</p>
                </div>
                <div class="kyc-card-body">
                    <div class="kyc-form-group">
                        <label class="kyc-label">Nome completo <span style="color:#e74c3c;">*</span></label>
                        <input type="text" name="full_name" id="full_name" class="kyc-input"
                            placeholder="Como aparece no documento"
                            value="{{ old('full_name', $profile->name) }}" required>
                        <small style="color:#888;font-size:11px;margin-top:4px;display:block;">Exatamente como está no seu documento oficial</small>
                    </div>

                    <div class="kyc-form-group">
                        <label class="kyc-label">Número do documento <span style="color:#e74c3c;">*</span></label>
                        <input type="text" name="document_number" id="document_number" class="kyc-input"
                            placeholder="Ex: 1234567 (RG) ou 123456789-01 (CNH)"
                            value="{{ old('document_number', $profile->document_number) }}" required>
                        <small style="color:#888;font-size:11px;margin-top:4px;display:block;">Número do RG, CNH ou Passaporte</small>
                    </div>

                    <div class="kyc-form-group">
                        <label class="kyc-label">Telefone / WhatsApp</label>
                        <input type="text" name="mobile" class="kyc-input"
                            placeholder="+55 (11) 99999-9999"
                            value="{{ old('mobile', $profile->mobile) }}">
                    </div>

                    <div style="display:flex;gap:10px;margin-top:8px;">
                        <button type="button" class="kyc-btn-back" onclick="kycBack()"><i class="fa fa-arrow-left"></i> Voltar</button>
                        <button type="button" class="kyc-btn-next" style="flex:1;" onclick="kycValidateStep4()">
                            Próximo <i class="fa fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>

            {{-- STEP 5: Address --}}
            <div class="kyc-step-panel" id="panel-5">
                <div class="kyc-card-header">
                    <span class="step-badge">Passo 5 de 6</span>
                    <h3><i class="fa fa-map-marker" style="color:#f5a623;margin-right:8px;"></i>Seu Endereço</h3>
                    <p>Precisamos do seu endereço de residência para conformidade.</p>
                </div>
                <div class="kyc-card-body">
                    <div class="kyc-form-group">
                        <label class="kyc-label">CEP <span style="color:#e74c3c;">*</span></label>
                        <input type="text" name="zip" id="kyc_zip" class="kyc-input"
                            placeholder="00000-000"
                            value="{{ old('zip', $profile->zip) }}"
                            oninput="buscarCEP(this.value)" maxlength="9" required>
                    </div>
                    <div class="kyc-form-group">
                        <label class="kyc-label">Rua / Endereço <span style="color:#e74c3c;">*</span></label>
                        <input type="text" name="address" id="kyc_address" class="kyc-input"
                            placeholder="Rua, número, complemento"
                            value="{{ old('address', $profile->address) }}" required>
                    </div>
                    <div style="display:flex;gap:12px;">
                        <div class="kyc-form-group" style="flex:1;">
                            <label class="kyc-label">Cidade <span style="color:#e74c3c;">*</span></label>
                            <input type="text" name="city" id="kyc_city" class="kyc-input"
                                placeholder="Cidade"
                                value="{{ old('city', $profile->city) }}" required>
                        </div>
                        <div class="kyc-form-group" style="width:100px;">
                            <label class="kyc-label">Estado</label>
                            <input type="text" name="state" id="kyc_state" class="kyc-input"
                                placeholder="UF" maxlength="2"
                                value="{{ old('state', $profile->state ?? '') }}">
                        </div>
                    </div>
                    <div class="kyc-form-group">
                        <label class="kyc-label">País</label>
                        <input type="text" name="country" class="kyc-input"
                            placeholder="Brasil"
                            value="{{ old('country', $profile->country ?? 'Brasil') }}">
                    </div>

                    <div style="display:flex;gap:10px;margin-top:8px;">
                        <button type="button" class="kyc-btn-back" onclick="kycBack()"><i class="fa fa-arrow-left"></i> Voltar</button>
                        <button type="button" class="kyc-btn-next" style="flex:1;" onclick="kycValidateStep5()">
                            Próximo <i class="fa fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>

            {{-- STEP 6: Terms --}}
            <div class="kyc-step-panel" id="panel-6">
                @if($profile->terms_accepted_at)
                {{-- All steps done: show AI result / waiting screen --}}
                <?php
                    $aiRaw = $profile->kyc_ai_result ? json_decode($profile->kyc_ai_result, true) : [];
                    $selfieStatus   = (int)$profile->selfie_verified;
                    $identityStatus = (int)$profile->identity_verified;
                    $selfieReason   = $aiRaw['selfie_reason']   ?? '';
                    $identityReason = $aiRaw['identity_reason'] ?? '';
                    if ($selfieStatus === 2 && ($aiRaw['selfie'] ?? null) !== 2)   $selfieReason = '';
                    if ($identityStatus === 2 && ($aiRaw['identity'] ?? null) !== 2) $identityReason = '';
                    $anyRejected    = ($selfieStatus === 2 || $identityStatus === 2);
                    $allApproved    = ($selfieStatus === 1 && $identityStatus === 1);
                ?>
                <div class="kyc-card-header">
                    <span class="step-badge">{{ $allApproved ? 'Verificado' : ($anyRejected ? 'Ação Necessária' : 'Em Análise') }}</span>
                    <h3>
                        @if($allApproved)
                            <i class="fa fa-check-circle" style="color:#27ae60;margin-right:8px;"></i>Documentos Verificados
                        @elseif($anyRejected)
                            <i class="fa fa-exclamation-circle" style="color:#e74c3c;margin-right:8px;"></i>Documento Rejeitado
                        @else
                            <i class="fa fa-hourglass-half" style="color:#f5a623;margin-right:8px;"></i>Aguardando Análise
                        @endif
                    </h3>
                    <p>Resultado da verificação dos seus documentos.</p>
                </div>
                <div class="kyc-card-body" style="padding:24px 16px;">

                    {{-- Status cards --}}
                    <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px;">

                        {{-- Selfie --}}
                        <div style="display:flex;align-items:flex-start;gap:12px;padding:14px;border-radius:10px;
                            background:{{ $selfieStatus===1 ? '#f0fdf4' : ($selfieStatus===2 ? '#fff5f5' : '#fffbeb') }};
                            border:1px solid {{ $selfieStatus===1 ? '#86efac' : ($selfieStatus===2 ? '#fca5a5' : '#fde68a') }};">
                            <div style="font-size:22px;margin-top:2px;">
                                @if($selfieStatus===1) <i class="fa fa-check-circle" style="color:#22c55e;"></i>
                                @elseif($selfieStatus===2) <i class="fa fa-times-circle" style="color:#ef4444;"></i>
                                @else <i class="fa fa-clock-o" style="color:#f59e0b;"></i>
                                @endif
                            </div>
                            <div>
                                <strong style="font-size:14px;">Selfie</strong>
                                <div style="font-size:13px;color:#555;margin-top:2px;">
                                    @if($selfieStatus===1) Aprovada ✓
                                    @elseif($selfieStatus===2) Rejeitada — {{ $selfieReason ?: 'Selfie inválida. Envie uma nova foto do rosto.' }}
                                    @else Em análise / aguardando revisão
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Documento --}}
                        <div style="display:flex;align-items:flex-start;gap:12px;padding:14px;border-radius:10px;
                            background:{{ $identityStatus===1 ? '#f0fdf4' : ($identityStatus===2 ? '#fff5f5' : '#fffbeb') }};
                            border:1px solid {{ $identityStatus===1 ? '#86efac' : ($identityStatus===2 ? '#fca5a5' : '#fde68a') }};">
                            <div style="font-size:22px;margin-top:2px;">
                                @if($identityStatus===1) <i class="fa fa-check-circle" style="color:#22c55e;"></i>
                                @elseif($identityStatus===2) <i class="fa fa-times-circle" style="color:#ef4444;"></i>
                                @else <i class="fa fa-clock-o" style="color:#f59e0b;"></i>
                                @endif
                            </div>
                            <div>
                                <strong style="font-size:14px;">Documento de Identidade</strong>
                                <div style="font-size:13px;color:#555;margin-top:2px;">
                                    @if($identityStatus===1) Aprovado ✓
                                    @elseif($identityStatus===2) Rejeitado — {{ $identityReason ?: 'Documento inválido. Envie uma nova foto clara do documento.' }}
                                    @else {{ $identityReason ?: 'Em análise / aguardando revisão da equipe.' }}
                                    @endif
                                </div>
                            </div>
                        </div>

                    </div>

                    @if(!$allApproved)
                    <div style="text-align:center;">
                        @if($anyRejected)
                        <p style="color:#666;font-size:13px;margin-bottom:16px;">Corrija os itens acima e reenvie seus documentos.</p>
                        @else
                        <p style="color:#666;font-size:13px;margin-bottom:16px;">Você pode reenviar fotos melhores ou aguardar a revisão manual da equipe.</p>
                        @endif
                        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:16px;">
                            {{-- Show selfie button if selfie not approved, OR if in manual review (face may not match) --}}
                            @if($selfieStatus !== 1 || $identityStatus === 0)
                            <button type="button" onclick="kycCurrentStep=2;kycUpdateUI();"
                                class="kyc-btn-next" style="max-width:200px;margin:0;">
                                <i class="fa fa-camera"></i> Nova Selfie
                            </button>
                            @endif
                            @if($identityStatus !== 1)
                            <button type="button" onclick="kycCurrentStep=3;kycUpdateUI();"
                                class="kyc-btn-next" style="max-width:200px;margin:0;background:#1a2035;color:#fff;">
                                <i class="fa fa-id-card"></i> Novo Documento
                            </button>
                            @endif
                        </div>
                        <div style="font-size:12px;color:#888;">
                            <i class="fa fa-info-circle"></i> Nossa equipe também pode aprovar manualmente caso as fotos estejam corretas.
                        </div>
                    </div>
                    @endif
                </div>
                @else
                <div class="kyc-card-header">
                    <span class="step-badge">Passo 6 de 6</span>
                    <h3><i class="fa fa-file-text-o" style="color:#f5a623;margin-right:8px;"></i>Termos e Contrato</h3>
                    <p>Leia e aceite os termos para finalizar seu cadastro.</p>
                </div>
                <div class="kyc-card-body">
                    <div class="kyc-terms-box">
                        <h4 style="color:#1a2035;margin-top:0;">CONTRATO DE PARTICIPAÇÃO — GOLDOFIR ECOSSISTEMA WEB3</h4>
                        <p style="font-size:11px;color:#888;">Leia com atenção antes de aceitar. Este contrato registra sua ciência sobre o histórico da plataforma, seus direitos e obrigações.</p>

                        <p style="font-size:10px;color:#555;border-left:3px solid #f5a623;padding-left:8px;margin-bottom:14px;line-height:1.6;">Plataforma desenvolvida e licenciada pela <strong>Gold Ofir Financial Solutions LLC</strong>, sociedade constituída sob as leis da Geórgia, com sede em Tbilisi, titular da marca e da tecnologia GolPay.</p>

                        <p><strong>1. SOBRE A PLATAFORMA</strong><br>
                        O GOLDOFIR é um ecossistema Web3 ativo desde 2018, que inclui o aplicativo de cashback sobre vendas reais GolPay.app, a rede blockchain GolChain e o token nativo FGOL. A participação na plataforma é voluntária e implica aceitação integral destes termos.</p>

                        <p><strong>2. CIÊNCIA SOBRE O HISTÓRICO E SALDO DE TOKENS</strong><br>
                        O usuário declara ter ciência de que a plataforma GOLDOFIR está em operação desde 2018 e que o saldo total de todos os airdrops de FGOL acumulados em sua conta ficou disponível para resgate durante todo o tempo. Os Relatórios Anuais do ecossistema sempre estiveram acessíveis aos usuários que completaram a verificação de identidade (KYC). O usuário reconhece que, caso não tenha verificado sua conta anteriormente, essa escolha foi de sua exclusiva responsabilidade, não cabendo qualquer reclamação contra o GOLDOFIR por eventuais tokens não resgatados ou informações não acessadas nesse intervalo. A verificação de conta passou a ser obrigatória para acesso ao painel e aos ativos digitais a partir de 2026.</p>

                        <p><strong>3. VERIFICAÇÃO DE IDENTIDADE (KYC)</strong><br>
                        O usuário declara que as informações e documentos fornecidos são verdadeiros, legítimos e pertencentes a si mesmo. A falsificação de documentos é crime previsto no Código Penal Brasileiro (Art. 297).</p>

                        <p><strong>4. TOKEN FGOL — NATUREZA JURÍDICA E REGULAÇÃO</strong><br>
                        O token FGOL é um ativo digital utilitário usado como cashback sobre consumo real na plataforma GolPay e usado para pagamento de gás na blockchain da GolChain no consórcio Blockum. Não existe promessa de valorização, rendimento fixo ou variável, nem captação pública de recursos em troca do token. Por não reunir as características do teste de Howey nem da definição de valor mobiliário prevista na Lei 6.385/1976, o FGOL opera fora da regulação da Comissão de Valores Mobiliários (CVM). O FGOL não constitui ação, debênture, cota de fundo de investimento ou qualquer espécie de valor mobiliário.</p>

                        <p><strong>5. CARTEIRA DIGITAL AUTO-CUSTODIADA</strong><br>
                        A carteira de FGOL de cada usuário é uma Coinbase Smart Wallet (padrão ERC-4337), protegida por passkey do próprio dispositivo do usuário. O GOLDOFIR não armazena nem controla as chaves privadas — o controle da carteira é exclusivo do usuário. Por essa razão, o GOLDOFIR não opera como exchange nem como custodiante de ativos digitais. Eventuais perdas decorrentes de acesso indevido, perda de dispositivo ou descuido com credenciais são de responsabilidade exclusiva do usuário.</p>

                        <p><strong>6. RELATÓRIOS ANUAIS</strong><br>
                        O usuário tem acesso aos relatórios anuais do ecossistema GOLDOFIR, publicados anualmente com informações sobre operações e desenvolvimento da plataforma, condicionado à manutenção do status de conta verificada.</p>

                        <p><strong>7. PRIVACIDADE E DADOS</strong><br>
                        Os dados pessoais e documentos enviados são tratados em conformidade com a Lei Geral de Proteção de Dados (LGPD — Lei 13.709/2018). Não compartilhamos dados com terceiros sem consentimento do titular, salvo obrigação legal.</p>

                        <p><strong>8. RESPONSABILIDADES</strong><br>
                        O usuário é responsável pela segurança de suas credenciais de acesso e pela guarda de seu dispositivo de autenticação. O GOLDOFIR não se responsabiliza por perdas decorrentes de acesso não autorizado causado por negligência do usuário, nem por oscilações no valor do token FGOL.</p>

                        <p><strong>9. FORO</strong><br>
                        Fica eleito o foro da Comarca de São Paulo, Estado de São Paulo, para dirimir quaisquer controvérsias oriundas deste contrato, com renúncia expressa a qualquer outro, por mais privilegiado que seja.</p>

                        <p style="font-size:11px;color:#999;margin-top:16px;">Última atualização: Setembro 2026 · GOLDOFIR Ecossistema Web3</p>
                    </div>

                    <div class="kyc-terms-check" id="termsCheck" onclick="toggleTerms()">
                        <input type="checkbox" name="terms_accepted" id="termsCheckbox" value="1">
                        <label for="termsCheckbox">
                            Li e aceito os Termos de Uso, o Contrato de Participação e a Política de Privacidade do GOLDOFIR.
                        </label>
                    </div>

                    <div style="margin-top:12px;padding:12px 14px;background:#f8f9fa;border-radius:8px;border:1px solid #e9ecef;">
                        <p style="font-size:12px;color:#666;margin:0 0 8px 0;"><strong>Comunicações (opcional)</strong> — Conforme LGPD, seu consentimento é independente dos termos acima.</p>
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#444;">
                                <input type="checkbox" name="newsletter_email" id="newsletterEmail" value="1" style="width:16px;height:16px;" {{ auth()->user()->newsletter_email != 0 ? 'checked' : '' }}>
                                Aceito receber novidades, relatórios e promoções do GOLDOFIR e GolPay por <strong>e-mail</strong>.
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:#444;">
                                <input type="checkbox" name="newsletter_whatsapp" id="newsletterWhatsapp" value="1" style="width:16px;height:16px;" {{ auth()->user()->newsletter_whatsapp != 0 ? 'checked' : '' }}>
                                Aceito receber comunicações do GOLDOFIR e GolPay por <strong>WhatsApp</strong>.
                            </label>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;margin-top:16px;">
                        <button type="button" class="kyc-btn-back" onclick="kycBack()"><i class="fa fa-arrow-left"></i> Voltar</button>
                        <button type="button" class="kyc-btn-next" style="flex:1;" id="submitBtn" onclick="kycSubmit()" disabled>
                            <i class="fa fa-shield"></i> Enviar para verificação
                        </button>
                    </div>
                </div>
                @endif
            </div>

        </form>

        {{-- Processing overlay --}}
        <div class="kyc-processing" id="kycProcessing">
            <div class="kyc-spinner"></div>
            <h4 style="color:#1a2035;font-weight:700;margin-bottom:8px;"><i class="fa fa-cog fa-spin" style="color:#f5a623;margin-right:8px;"></i>IA verificando seus documentos...</h4>
            <p style="color:#888;font-size:14px;">Estamos comparando sua selfie com o documento e validando seus dados.<br>Isso leva apenas alguns segundos.</p>
            <div style="margin-top:20px;display:flex;gap:8px;justify-content:center;font-size:12px;color:#888;">
                <span id="step-ai-1" style="padding:6px 12px;border-radius:20px;background:#f0f0f0;">Analisando selfie</span>
                <span id="step-ai-2" style="padding:6px 12px;border-radius:20px;background:#f0f0f0;">Verificando documento</span>
                <span id="step-ai-3" style="padding:6px 12px;border-radius:20px;background:#f0f0f0;">Comparando rostos</span>
            </div>
        </div>

    </div>{{-- .kyc-card --}}
</div>{{-- .kyc-wrapper --}}

<script>
var kycCurrentStep = 1;
var kycTotalSteps  = 6;

function kycUpdateUI() {
    // Update panels
    document.querySelectorAll('.kyc-step-panel').forEach(function(p) { p.classList.remove('active'); });
    var panel = document.getElementById('panel-' + kycCurrentStep);
    if (panel) panel.classList.add('active');

    // Update stepper
    document.querySelectorAll('.kyc-step-item').forEach(function(el) {
        var s = parseInt(el.dataset.step);
        el.classList.remove('active','done');
        if (s === kycCurrentStep) el.classList.add('active');
        if (s < kycCurrentStep)  el.classList.add('done');
        // Icon for done
        var circle = el.querySelector('.kyc-step-circle');
        if (s < kycCurrentStep) circle.innerHTML = '<i class="fa fa-check"></i>';
    });

    // Update lines
    var lines = document.querySelectorAll('.kyc-step-line');
    lines.forEach(function(l, idx) {
        l.classList.toggle('done', idx < kycCurrentStep - 1);
    });

    // Progress
    var pct = Math.round((kycCurrentStep / kycTotalSteps) * 100);
    document.getElementById('kycProgressFill').style.width = pct + '%';
    document.getElementById('kycStepNum').textContent = kycCurrentStep;
}

// Early submit is only for re-uploads from the result screen (contract already accepted).
// On first-time onboarding files stay in the form and are sent together at step 6.
var kycHasTerms = {{ $profile->terms_accepted_at ? 'true' : 'false' }};

function kycNext() {
    // Validate files before advancing (iOS onchange may not fire for camera captures)
    if (kycCurrentStep === 2) {
        var selfieInput = document.getElementById('selfie');
        var hasNewFile  = selfieInput && selfieInput.files && selfieInput.files.length > 0;
        var hasSelfieDB = {{ $profile->selfie ? 'true' : 'false' }};
        var selfieApproved = {{ (int)$profile->selfie_verified === 1 ? 'true' : 'false' }};
        if (!hasNewFile && !hasSelfieDB && !selfieApproved) {
            alert('Por favor, tire sua selfie antes de continuar.');
            return;
        }
        if (hasNewFile) previewImg(selfieInput, 'selfiePreview', 'selfieZone', 'selfieIcon');
        // New file selected → submit form immediately to process it
        if (hasNewFile && kycHasTerms) { kycResubmitFiles(); return; }
    }
    if (kycCurrentStep === 3) {
        var identityInput = document.getElementById('identity');
        var hasNewFile2   = identityInput && identityInput.files && identityInput.files.length > 0;
        var hasIdentityDB = {{ $profile->identity ? 'true' : 'false' }};
        var identityApproved = {{ (int)$profile->identity_verified === 1 ? 'true' : 'false' }};
        if (!hasNewFile2 && !hasIdentityDB && !identityApproved) {
            alert('Por favor, envie uma foto do seu documento antes de continuar.');
            return;
        }
        if (hasNewFile2) previewImg(identityInput, 'identityPreview', 'identityZone', 'identityIcon');
        // New file selected → submit form immediately to process it
        if (hasNewFile2 && kycHasTerms) { kycResubmitFiles(); return; }
    }
    if (kycCurrentStep < kycTotalSteps) {
        kycCurrentStep++;
        kycUpdateUI();
        window.scrollTo(0, 0);
    }
}

// Submit form immediately (for mid-wizard re-uploads of selfie or document)
function kycResubmitFiles() {
    document.getElementById('kycForm').style.display = 'none';
    document.getElementById('kycProcessing').style.display = 'block';
    setTimeout(function() {
        document.getElementById('kycForm').submit();
    }, 1200);
}

function kycBack() {
    if (kycCurrentStep > 1) {
        kycCurrentStep--;
        kycUpdateUI();
        window.scrollTo(0, 0);
    }
}

function kycValidateStep4() {
    var name = document.getElementById('full_name').value.trim();
    var doc  = document.getElementById('document_number').value.trim();
    if (!name) { alert('Informe seu nome completo.'); return; }
    if (!doc)  { alert('Informe o número do documento.'); return; }
    kycNext();
}

function kycValidateStep5() {
    var zip  = document.getElementById('kyc_zip').value.trim();
    var addr = document.getElementById('kyc_address').value.trim();
    var city = document.getElementById('kyc_city').value.trim();
    if (!zip || !addr || !city) { alert('Preencha CEP, endereço e cidade.'); return; }
    kycNext();
}

// File preview
function previewImg(input, previewId, zoneId, iconId) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    var zone = document.getElementById(zoneId);
    zone.classList.add('has-file');

    if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
        // PDF: show icon instead of image preview
        var icon = document.getElementById(iconId);
        if (icon) icon.innerHTML = '<i class="fa fa-file-pdf-o fa-3x" style="color:#e74c3c;margin-bottom:8px;"></i><div style="font-weight:700;color:#1a2035;">' + file.name + '</div><div style="font-size:12px;color:#888;">PDF · ' + (file.size/1024).toFixed(0) + ' KB</div>';
        // Enable next button
        var btn = document.getElementById(zoneId === 'selfieZone' ? 'selfieNextBtn' : 'identityNextBtn');
        if (btn) { btn.disabled = false; btn.innerHTML = 'Continuar <i class="fa fa-arrow-right"></i>'; }
        return;
    }

    var reader = new FileReader();
    reader.onload = function(e) {
        var prev = document.getElementById(previewId);
        prev.src = e.target.result;
        prev.style.display = 'block';
        // Enable next button
        var btn = document.getElementById(zoneId === 'selfieZone' ? 'selfieNextBtn' : 'identityNextBtn');
        if (btn) { btn.disabled = false; btn.innerHTML = 'Continuar <i class="fa fa-arrow-right"></i>'; }
    };
    reader.readAsDataURL(file);
}

// Terms toggle
function toggleTerms() {
    var cb = document.getElementById('termsCheckbox');
    cb.checked = !cb.checked;
    var check = document.getElementById('termsCheck');
    check.classList.toggle('accepted', cb.checked);
    document.getElementById('submitBtn').disabled = !cb.checked;
}

// Resubmit doc only (from step 3 rejection flow)
function kycResubmitDoc() {
    var identityInput = document.getElementById('identity');
    if (!identityInput || !identityInput.files || !identityInput.files[0]) {
        alert('Selecione um novo documento antes de reenviar.');
        return;
    }
    // Show processing and submit
    document.getElementById('kycForm').style.display = 'none';
    document.getElementById('kycProcessing').style.display = 'block';
    setTimeout(function() {
        document.getElementById('kycForm').submit();
    }, 1800);
}

// Submit with animation
function kycSubmit() {
    var cb = document.getElementById('termsCheckbox');
    if (!cb.checked) { alert('Aceite os termos para continuar.'); return; }

    // Hide form, show processing
    document.getElementById('kycForm').style.display = 'none';
    var proc = document.getElementById('kycProcessing');
    proc.style.display = 'block';
    document.querySelector('.kyc-wrapper > div:first-child').style.display = 'none'; // hide progress

    // Animate AI steps
    var steps = ['step-ai-1','step-ai-2','step-ai-3'];
    var colors = ['#e3f2fd','#e8f5e9','#fff8e1'];
    steps.forEach(function(id, i) {
        setTimeout(function() {
            document.getElementById(id).style.background = colors[i];
            document.getElementById(id).style.color = '#1a2035';
            document.getElementById(id).style.fontWeight = '600';
        }, i * 1200);
    });

    // Submit form after animations
    setTimeout(function() {
        document.getElementById('kycForm').submit();
    }, 3800);
}

// CEP auto-complete (ViaCEP)
function buscarCEP(cep) {
    cep = cep.replace(/\D/g,'');
    if (cep.length === 8) {
        fetch('https://viacep.com.br/ws/' + cep + '/json/')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.erro) {
                var addr = document.getElementById('kyc_address');
                var city = document.getElementById('kyc_city');
                var state = document.getElementById('kyc_state');
                if (addr && !addr.value) addr.value = d.logradouro || '';
                if (city) city.value = d.localidade || '';
                if (state) state.value = d.uf || '';
            }
        }).catch(function(){});
    }
}

// iOS fallback: when user returns from native camera app, re-check files
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState !== 'visible') return;
    var s = document.getElementById('selfie');
    if (s && s.files && s.files.length > 0) previewImg(s, 'selfiePreview', 'selfieZone', 'selfieIcon');
    var d = document.getElementById('identity');
    if (d && d.files && d.files.length > 0) previewImg(d, 'identityPreview', 'identityZone', 'identityIcon');
});
window.addEventListener('focus', function() {
    var s = document.getElementById('selfie');
    if (s && s.files && s.files.length > 0) previewImg(s, 'selfiePreview', 'selfieZone', 'selfieIcon');
    var d = document.getElementById('identity');
    if (d && d.files && d.files.length > 0) previewImg(d, 'identityPreview', 'identityZone', 'identityIcon');
});

// CEP mask
document.getElementById('kyc_zip') && document.getElementById('kyc_zip').addEventListener('input', function() {
    var v = this.value.replace(/\D/g,'');
    if (v.length > 5) v = v.slice(0,5) + '-' + v.slice(5,8);
    this.value = v;
});

// Auto-advance based on current verification state
(function() {
    var selfieVerified  = {{ (int)$profile->selfie_verified }};
    var identityVerified = {{ (int)$profile->identity_verified }};
    var hasSelfie   = {{ $profile->selfie ? 'true' : 'false' }};
    var hasIdentity = {{ $profile->identity ? 'true' : 'false' }};

    var hasPersonalData = {{ ($profile->name && $profile->document_number) ? 'true' : 'false' }};
    var hasAddress      = {{ ($profile->address && $profile->city) ? 'true' : 'false' }};
    var hasTerms        = {{ $profile->terms_accepted_at ? 'true' : 'false' }};

    if (selfieVerified === 2) {
        kycCurrentStep = 2; // selfie rejected — re-upload
    } else if (!hasSelfie && selfieVerified !== 1) {
        kycCurrentStep = 2; // no selfie yet
    } else if (identityVerified === 2) {
        kycCurrentStep = 3; // identity rejected — re-upload
    } else if (!hasIdentity && identityVerified !== 1) {
        kycCurrentStep = 3; // no identity yet
    } else if (!hasPersonalData) {
        kycCurrentStep = 4; // fill personal data
    } else if (!hasAddress) {
        kycCurrentStep = 5; // fill address
    } else if (!hasTerms) {
        kycCurrentStep = 6; // sign contract
    } else {
        kycCurrentStep = 6; // all done — waiting for admin approval
    }
})();
kycUpdateUI();
</script>
@endif

@endsection
