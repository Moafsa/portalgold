<!DOCTYPE html>
@php
    try {
        $unreadNotifications = DB::table('notifications')
            ->where('user_id', Auth::id())
            ->where('read', false)
            ->count();
            
        $notifications = DB::table('notifications')
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
    } catch (\Exception $e) {
        Log::error('Erro ao buscar notificações: ' . $e->getMessage());
        $unreadNotifications = 0;
        $notifications = collect([]);
    }
@endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{$gnl->title}} | Dashboard</title>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" name="viewport" />
    <meta content="" name="description" />
    <meta content="" name="author" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <!-- Styles -->
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
    <link href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css" rel="stylesheet" />
    <link href="{{ asset('assets/user/plugins/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/user/plugins/font-awesome/css/font-awesome.min.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/user/css/animate.min.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/user/css/style.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/user/css/style-responsive.min.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/user/css/theme/default.css') }}" rel="stylesheet" id="theme" />
    <link rel="shortcut icon" href="{{ asset('assets/images/logo/icon.png') }}" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    
    <!-- Core Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="{{ asset('assets/user/plugins/bootstrap/js/bootstrap.min.js') }}"></script>
    
    <!-- SweetAlert -->
    <script src="{{ asset('assets/user/plugins/bootstrap-sweetalert/sweetalert.min.js') }}"></script>
    <link href="{{ asset('assets/user/plugins/bootstrap-sweetalert/sweetalert.css') }}" rel="stylesheet" />
    
    <!-- Moment.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.1/moment.min.js"></script>
    
    <!-- Other Scripts -->
    <script src="{{ asset('assets/user/plugins/jquery-cookie/jquery.cookie.js') }}"></script>
    <script src="{{ asset('assets/user/plugins/slimscroll/jquery.slimscroll.min.js') }}"></script>
    <script src="{{ asset('assets/user/plugins/pace/pace.min.js') }}"></script>
    
    <!-- App Scripts -->
    <script src="{{ asset('assets/user/js/apps.min.js') }}"></script>
    
    <script>
        window.jQuery || document.write('<script src="{{ asset("assets/user/plugins/jquery/jquery.min.js") }}"><\/script>')
    </script>
</head>
<body class="dark-mode">
    <div id="page-loader" class="fade in"><span class="spinner"></span></div>
    
    <div id="page-container" class="fade page-sidebar-fixed page-header-fixed">
        @if(!Auth::user()->verified)
        <div class="alert alert-danger text-center" style="margin: 0; border-radius: 0;">
            Account not verified. Please verify your account.
            <a href="{{uroute('profile.verification')}}" class="btn btn-danger btn-sm">Verify Now</a>
        </div>
        @endif
        @if(Auth::user()->verified && empty(Auth::user()->wallet))
        <div class="alert alert-warning text-center" style="margin: 0; border-radius: 0;">
            <i class="fa fa-wallet"></i> Conta verificada! Falta criar sua carteira GOL para receber seus tokens.
            <a href="{{uroute('profile.verification')}}" class="btn btn-warning btn-sm" style="margin-left:8px;">Criar carteira</a>
        </div>
        @endif

        <div id="header" class="header navbar navbar-default navbar-fixed-top">
            <div class="container-fluid">
                <div class="navbar-header">
                    <a href="{{uroute('home')}}" class="navbar-brand">
                        <img class="img-responsive" src="{{ asset('assets/images/logo/logo.png') }}" style="max-width: 60%;">
                    </a>
                    
                    <!-- Botões de notificação e tema -->
                    <div class="header-nav visible-xs">
                        <div class="header-buttons">
                            <div class="dropdown">
                                <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                    <i class="fa fa-bell"></i>
                                    @if($unreadNotifications > 0)
                                        <span class="badge badge-danger">{{$unreadNotifications}}</span>
                                    @endif
                                </a>
                                <ul class="dropdown-menu notifications-menu">
                                    @forelse($notifications as $notification)
                                        <li class="notification-item {{$notification->read ? '' : 'unread'}}">
                                            <a href="{{uroute('notifications.index')}}" class="notification-link">
                                                <i class="fa fa-{{$notification->type == 'success' ? 'check' : 'exclamation'}}-circle text-{{$notification->type}}"></i>
                                                <div class="notification-content">
                                                    <div class="notification-title">{{$notification->title}}</div>
                                                    <div class="notification-message">{{$notification->message}}</div>
                                                    <div class="notification-time">{{Carbon\Carbon::parse($notification->created_at)->diffForHumans()}}</div>
                                                </div>
                                            </a>
                                        </li>
                                    @empty
                                        <li class="no-notifications">
                                            <p class="text-center">No notifications</p>
                                        </li>
                                    @endforelse
                                    <li class="notification-footer">
                                        <a href="{{uroute('notifications.index')}}" class="view-all">Ver todas notificações</a>
                                    </li>
                                </ul>
                            </div>
                            <a href="#" class="theme-toggle" onclick="toggleTheme()">
                                <i class="fa fa-adjust"></i>
                            </a>
                            <button type="button" class="navbar-toggle" data-click="sidebar-toggled">
                                <span class="icon-bar"></span>
                                <span class="icon-bar"></span>
                                <span class="icon-bar"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="collapse navbar-collapse" id="header-navbar">
                    <ul class="nav navbar-nav navbar-right">
                        @php
                            $balance = \App\Address::where('user_id', Auth::id())->sum('balance');
                        @endphp
                        <li class="dropdown hidden-xs">
                            <div class="dropdown">
                                <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                    <i class="fa fa-bell"></i>
                                    @if($unreadNotifications > 0)
                                        <span class="badge badge-danger">{{$unreadNotifications}}</span>
                                    @endif
                                </a>
                                <ul class="dropdown-menu notifications-menu">
                                    @forelse($notifications as $notification)
                                        <li class="notification-item {{$notification->read ? '' : 'unread'}}">
                                            <a href="{{uroute('notifications.index')}}" class="notification-link">
                                                <i class="fa fa-{{$notification->type == 'success' ? 'check' : 'exclamation'}}-circle text-{{$notification->type}}"></i>
                                                <div class="notification-content">
                                                    <div class="notification-title">{{$notification->title}}</div>
                                                    <div class="notification-message">{{$notification->message}}</div>
                                                    <div class="notification-time">{{Carbon\Carbon::parse($notification->created_at)->diffForHumans()}}</div>
                                                </div>
                                            </a>
                                        </li>
                                    @empty
                                        <li class="no-notifications">
                                            <p class="text-center">No notifications</p>
                                        </li>
                                    @endforelse
                                    <li class="notification-footer">
                                        <a href="{{uroute('notifications.index')}}" class="view-all">Ver todas notificações</a>
                                    </li>
                                </ul>
                            </div>
                        </li>
                        <li class="dropdown hidden-xs">
                            <a href="#" class="theme-toggle" onclick="toggleTheme()">
                                <i class="fa fa-adjust"></i>
                            </a>
                        </li>
                        <li class="dropdown hidden-xs">
                            <a href="#" style="font-size: 20px; color:#fff; margin-right: 20px;">
                                BALANCE = {{round($balance , $gnl->decimal)}} {{$gnl->cur}}
                            </a>
                        </li>
                        <li class="dropdown hidden-xs">
                            <a href="#" style="font-size: 20px; color:#fff; margin-right: 20px;">
                                1 {{$gnl->cur}} = {{round($price , $gnl->decimal)}} BRL
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        @include('layouts.sidebar')
        
        <div id="content" class="content">
            @include('layouts.alert')
            @yield('content')
        </div>
    </div>

    <script>
        $(document).ready(function() {
            if (typeof App !== 'undefined') {
                try {
                    App.init();
                } catch (e) {
                    console.error('Erro ao inicializar App:', e);
                }
            }
            
            // Verifica o tema salvo no localStorage
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
            } else {
                document.body.classList.remove('dark-mode');
            }

            $('[data-click="sidebar-toggled"]').click(function() {
                $('body').toggleClass('page-sidebar-toggled');
            });

            // Fecha o menu ao clicar em um item no modo responsivo
            $('.navbar-nav > li > a').click(function(){
                if($(window).width() < 768) {
                    $('.navbar-collapse').collapse('hide');
                }
            });

            // Ajusta o layout quando a janela é redimensionada
            $(window).resize(function() {
                if($(window).width() > 767) {
                    $('.navbar-collapse').collapse('hide');
                    $('body').removeClass('page-sidebar-toggled');
                }
            });
        });

        function toggleTheme() {
            document.body.classList.toggle('dark-mode');
            const isDark = document.body.classList.contains('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        }
    </script>

    <!-- GOLDOFIR AI Chatbot Widget -->
<style>
#gf-chat-bubble {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    width: 56px; height: 56px; border-radius: 50%;
    background: linear-gradient(135deg, #1a2035, #0052ff);
    color: #fff; font-size: 22px; border: none; cursor: pointer;
    box-shadow: 0 4px 16px rgba(0,82,255,.45);
    display: flex; align-items: center; justify-content: center;
    transition: transform .2s, box-shadow .2s;
}
#gf-chat-bubble:hover { transform: scale(1.08); box-shadow: 0 6px 24px rgba(0,82,255,.6); }
#gf-chat-bubble .gf-badge {
    position: absolute; top: -4px; right: -4px;
    background: #f5a623; color: #fff; border-radius: 50%;
    width: 18px; height: 18px; font-size: 10px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}
#gf-chat-panel {
    display: none; position: fixed; bottom: 90px; right: 24px; z-index: 9998;
    width: 360px; max-width: calc(100vw - 32px);
    background: #fff; border-radius: 16px;
    box-shadow: 0 8px 40px rgba(0,0,0,.18);
    flex-direction: column; overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
#gf-chat-panel.open { display: flex; }
#gf-chat-header {
    background: linear-gradient(135deg, #1a2035, #0052ff);
    color: #fff; padding: 14px 16px;
    display: flex; align-items: center; gap: 10px;
}
#gf-chat-header .gf-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: #f5a623; display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
#gf-chat-header .gf-info { flex: 1; }
#gf-chat-header .gf-info strong { display: block; font-size: 14px; }
#gf-chat-header .gf-info small { color: rgba(255,255,255,.7); font-size: 11px; }
#gf-chat-header button {
    background: none; border: none; color: rgba(255,255,255,.7);
    font-size: 18px; cursor: pointer; padding: 0 4px;
}
#gf-chat-header button:hover { color: #fff; }
#gf-chat-messages {
    flex: 1; overflow-y: auto; padding: 16px;
    display: flex; flex-direction: column; gap: 10px;
    min-height: 300px; max-height: 380px;
    background: #f8f9fc;
}
.gf-msg { display: flex; gap: 8px; animation: gfFadeIn .2s ease; }
@keyframes gfFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; } }
.gf-msg.user { flex-direction: row-reverse; }
.gf-msg-bubble {
    max-width: 80%; padding: 9px 13px; border-radius: 14px;
    font-size: 13px; line-height: 1.5; word-break: break-word;
}
.gf-msg.bot .gf-msg-bubble { background: #fff; color: #333; box-shadow: 0 1px 4px rgba(0,0,0,.08); border-top-left-radius: 4px; }
.gf-msg.user .gf-msg-bubble { background: #0052ff; color: #fff; border-top-right-radius: 4px; }
.gf-msg-avatar {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    background: #f5a623; display: flex; align-items: center; justify-content: center;
    font-size: 12px; color: #fff; align-self: flex-end;
}
.gf-typing { display: flex; gap: 4px; padding: 10px 14px; background: #fff; border-radius: 14px; border-top-left-radius: 4px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.gf-typing span { width: 7px; height: 7px; border-radius: 50%; background: #aaa; animation: gfDot 1.2s infinite; }
.gf-typing span:nth-child(2) { animation-delay: .2s; }
.gf-typing span:nth-child(3) { animation-delay: .4s; }
@keyframes gfDot { 0%,80%,100% { transform: scale(.7); opacity:.5; } 40% { transform: scale(1); opacity:1; } }
#gf-chat-handover { padding: 8px 16px; border-top: 1px solid #eee; background: #fff; }
#gf-chat-handover button {
    width: 100%; padding: 7px; border-radius: 8px; border: 1px solid #e0e0e0;
    background: #fff; color: #666; font-size: 12px; cursor: pointer;
    transition: all .2s;
}
#gf-chat-handover button:hover { background: #f5a623; color: #fff; border-color: #f5a623; }
#gf-chat-input-area {
    display: flex; gap: 8px; padding: 12px 16px;
    border-top: 1px solid #eee; background: #fff;
}
#gf-chat-input {
    flex: 1; border: 1px solid #e0e0e0; border-radius: 22px;
    padding: 8px 14px; font-size: 13px; outline: none;
    transition: border-color .2s;
}
#gf-chat-input:focus { border-color: #0052ff; }
#gf-chat-send {
    width: 36px; height: 36px; border-radius: 50%;
    background: #0052ff; color: #fff; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; transition: background .2s;
}
#gf-chat-send:hover { background: #003ecc; }
#gf-chat-send:disabled { background: #ccc; cursor: default; }

/* Chatbot dark mode — follows system theme vars */
body.dark-mode #gf-chat-panel { background: var(--bg-light); box-shadow: 0 8px 40px rgba(0,0,0,.4); }
body.dark-mode #gf-chat-messages { background: var(--primary-dark); }
body.dark-mode .gf-msg.bot .gf-msg-bubble { background: var(--bg-light); color: var(--text-light); box-shadow: 0 1px 4px rgba(0,0,0,.25); }
body.dark-mode #gf-chat-input-area { background: var(--bg-light); border-top: 1px solid var(--border-color); }
body.dark-mode #gf-chat-input { background: var(--primary-dark) !important; border-color: var(--border-color) !important; color: var(--text-light) !important; }
body.dark-mode #gf-chat-input::placeholder { color: var(--text-muted); }
body.dark-mode #gf-chat-input:focus { border-color: var(--primary-light) !important; }
body.dark-mode #gf-chat-handover { background: var(--bg-light); border-top: 1px solid var(--border-color); }
body.dark-mode #gf-chat-handover button { background: var(--primary-dark); border-color: var(--border-color); color: var(--text-muted); }
body.dark-mode #gf-chat-handover button:hover { background: #f5a623; color: #fff; border-color: #f5a623; }
body.dark-mode .gf-typing { background: var(--bg-light); }
body.dark-mode .gf-typing span { background: var(--text-muted); }
</style>

<button id="gf-chat-bubble" onclick="gfChatToggle()" title="Assistente GOLDOFIR">
    <i class="fa fa-comments"></i>
    <span class="gf-badge" id="gf-chat-badge" style="display:none;">1</span>
</button>

<div id="gf-chat-panel">
    <div id="gf-chat-header">
        <div class="gf-avatar"><i class="fa fa-star"></i></div>
        <div class="gf-info">
            <strong>Assistente GOLDOFIR</strong>
            <small>Assistente Virtual · Online</small>
        </div>
        <button onclick="gfChatToggle()" title="Fechar"><i class="fa fa-times"></i></button>
    </div>
    <div id="gf-chat-messages"></div>
    <div id="gf-chat-handover">
        <button onclick="gfHandover()"><i class="fa fa-headphones"></i> Falar com atendente humano</button>
    </div>
    <div id="gf-chat-input-area">
        <input id="gf-chat-input" type="text" placeholder="Digite sua mensagem..."
               onkeydown="if(event.key==='Enter')gfSend()">
        <button id="gf-chat-send" onclick="gfSend()"><i class="fa fa-paper-plane"></i></button>
    </div>
</div>

<script>
(function() {
    var GF = {
        history: [],
        open: false,
        sending: false,
        CHAT_URL: '{{ auth()->check() ? uroute("chatbot.chat") : "/chatbot/message" }}',
        HO_URL: '{{ auth()->check() ? uroute("chatbot.handover") : "/chatbot/handover" }}',
        SEND_URL: '{{ auth()->check() ? uroute("chatbot.transcript") : "" }}',
        CSRF: (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
    };

    // Show welcome message after short delay
    setTimeout(function() {
        gfAppendMsg('bot', 'Olá! Sou o assistente da GOLDOFIR 👋 Como posso ajudar você hoje?');
        document.getElementById('gf-chat-badge').style.display = 'flex';
    }, 2500);

    window.gfChatToggle = function() {
        GF.open = !GF.open;
        var panel = document.getElementById('gf-chat-panel');
        var badge = document.getElementById('gf-chat-badge');
        panel.classList.toggle('open', GF.open);
        badge.style.display = 'none';
        if (GF.open) setTimeout(function() { document.getElementById('gf-chat-input').focus(); }, 200);
    };

    window.gfSend = function() {
        if (GF.sending) return;
        var input = document.getElementById('gf-chat-input');
        var msg = input.value.trim();
        if (!msg) return;
        input.value = '';
        gfAppendMsg('user', msg);
        GF.history.push({ role: 'user', content: msg });
        gfShowTyping();
        GF.sending = true;
        document.getElementById('gf-chat-send').disabled = true;

        fetch(GF.CHAT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': GF.CSRF },
            body: JSON.stringify({ message: msg, history: GF.history.slice(-10) }),
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            gfHideTyping();
            var reply = d.reply || 'Desculpe, não consegui processar sua mensagem.';
            gfAppendMsg('bot', reply);
            GF.history.push({ role: 'assistant', content: reply });
        })
        .catch(function() {
            gfHideTyping();
            gfAppendMsg('bot', 'Erro de conexão. Tente novamente ou contate contato@goldofir.me');
        })
        .finally(function() {
            GF.sending = false;
            document.getElementById('gf-chat-send').disabled = false;
        });
    };

    window.gfHandover = function() {
        var btn = document.querySelector('#gf-chat-handover button');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Enviando...';

        fetch(GF.HO_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': GF.CSRF },
            body: JSON.stringify({ history: GF.history }),
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            gfAppendMsg('bot', d.message || 'Transferido para a equipe!');
            btn.innerHTML = '<i class="fa fa-check"></i> Solicitação enviada';
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-headphones"></i> Falar com atendente humano';
        });
    };

    function gfAppendMsg(role, text) {
        var msgs = document.getElementById('gf-chat-messages');
        var div = document.createElement('div');
        div.className = 'gf-msg ' + role;
        var avatar = '<div class="gf-msg-avatar">' + (role === 'bot' ? '<i class="fa fa-star"></i>' : '<i class="fa fa-user"></i>') + '</div>';
        var bubble = '<div class="gf-msg-bubble">' + gfEscape(text) + '</div>';
        div.innerHTML = role === 'bot' ? (avatar + bubble) : (bubble + avatar);
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
        // Show badge if panel is closed
        if (!GF.open && role === 'bot') {
            document.getElementById('gf-chat-badge').style.display = 'flex';
        }
    }

    var typingEl = null;
    function gfShowTyping() {
        var msgs = document.getElementById('gf-chat-messages');
        typingEl = document.createElement('div');
        typingEl.className = 'gf-msg bot';
        typingEl.innerHTML = '<div class="gf-msg-avatar"><i class="fa fa-star"></i></div>'
            + '<div class="gf-typing"><span></span><span></span><span></span></div>';
        msgs.appendChild(typingEl);
        msgs.scrollTop = msgs.scrollHeight;
    }
    function gfHideTyping() {
        if (typingEl && typingEl.parentNode) typingEl.parentNode.removeChild(typingEl);
        typingEl = null;
    }

    function gfEscape(text) {
        return text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                   .replace(/\n/g,'<br>');
    }
    // Auto-send transcript to user email on page unload (only if conversation started)
    window.addEventListener('beforeunload', function() {
        if (GF.SEND_URL && GF.history.length >= 2) {
            var blob = new Blob([JSON.stringify({ history: GF.history })], { type: 'application/json' });
            navigator.sendBeacon(GF.SEND_URL, blob);
        }
    });
})();
</script>


    <style>
    /* Tema Dark com cores da empresa */
    :root {
        --primary-dark: #051824;      /* Azul escuro mais profundo para fundo */
        --primary-light: #00ACAC;     /* Ciano da empresa */
        --bg-light: #0A2435;          /* Azul escuro para elementos */
        --text-light: #FFFFFF;        /* Texto mais claro para melhor contraste */
        --text-dark: #0B2435;         /* Texto escuro para modo light */
        --text-muted: #A4B1CD;        /* Texto secundário */
        --border-color: #0D3251;      /* Bordas mais escuras */
        --success-color: #00ACAC;     /* Sucesso usando o ciano da empresa */
        --danger-color: #FF5B57;      /* Vermelho mais vibrante */
        --warning-color: #FFB822;     /* Amarelo mais vibrante */
        --hover-color: #008F8F;       /* Hover mais escuro */
        --header-bg: #051824;         /* Fundo do header */
        --sidebar-bg: #051824;        /* Fundo da sidebar */
        --table-text: #0B2435;        /* Cor do texto das tabelas */
    }

    body {
        background: #FFFFFF;
    }

    body.dark-mode {
        background: var(--primary-dark);
        color: var(--text-light);
    }

    /* Header e Sidebar */
    .dark-mode #header {
        background: var(--header-bg) !important;
        border-bottom: 1px solid var(--border-color);
    }

    .dark-mode #sidebar {
        background: var(--sidebar-bg) !important;
    }

    .dark-mode .sidebar .nav > li > a {
        color: var(--text-light);
    }

    .dark-mode .sidebar .nav > li.active > a {
        background: var(--primary-light) !important;
        color: var(--text-light);
        font-weight: 600;
    }

    .dark-mode .sidebar .nav > li > a:hover {
        background: var(--bg-light);
    }

    /* Painéis e Portlets */
    .dark-mode .portlet.light.bordered {
        background-color: var(--bg-light);
        border: 1px solid var(--border-color) !important;
    }

    .dark-mode .portlet-title {
        background-color: var(--header-bg) !important;
        border-bottom: 2px solid var(--border-color);
    }

    .dark-mode .caption-subject {
        color: var(--primary-light) !important;
        font-weight: 600;
    }

    /* Tabelas */
    .dark-mode .table {
        background-color: var(--bg-light);
    }

    .dark-mode .table > thead > tr > th {
        background-color: var(--header-bg);
        color: var(--primary-light) !important;
        border-bottom: 2px solid var(--border-color);
        font-weight: 600;
        text-transform: uppercase;
        font-size: 12px;
    }

    .dark-mode .table > tbody > tr > td {
        color: var(--text-light) !important;
        border-color: var(--border-color);
        padding: 12px 8px;
    }

    .dark-mode .table-striped > tbody > tr:nth-of-type(odd) {
        background-color: var(--primary-dark);
    }

    .dark-mode .table-striped > tbody > tr:nth-of-type(even) {
        background-color: var(--bg-light);
    }

    .dark-mode .table > tbody > tr:hover {
        background-color: rgba(0, 172, 172, 0.15);
    }

    /* Botões */
    .btn-primary, .dark-mode .btn-primary {
        background-color: #0B2435 !important;
        border-color: #0B2435 !important;
        color: #FFFFFF !important;
    }

    .btn-success, .dark-mode .btn-success {
        background-color: #0B2435 !important;
        border-color: #0B2435 !important;
        color: #FFFFFF !important;
    }

    .btn:hover, .dark-mode .btn:hover {
        background-color: #051824 !important;
        border-color: #051824 !important;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    /* Formulários */
    .dark-mode .form-control {
        background-color: var(--primary-dark);
        border: 1px solid var(--border-color);
        color: var(--text-light);
    }

    .dark-mode .form-control:focus {
        background-color: var(--primary-dark);
        border-color: var(--primary-light);
        box-shadow: 0 0 0 2px rgba(0, 172, 172, 0.25);
    }

    /* Notificações */
    .dark-mode .notifications-menu {
        background-color: var(--bg-light);
        border: 1px solid var(--border-color);
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    .dark-mode .notification-item {
        border-bottom: 1px solid var(--border-color);
        padding: 12px 15px;
    }

    .dark-mode .notification-title {
        color: var(--primary-light);
        font-weight: 600;
    }

    .dark-mode .notification-message {
        color: var(--text-light);
    }

    .dark-mode .notification-time {
        color: var(--text-muted);
    }

    /* Aviso de verificação */
    .dark-mode .alert-danger {
        background-color: rgba(5, 24, 36, 0.97);
        border: 1px solid var(--primary-light);
        color: var(--text-light);
    }

    .dark-mode .alert-danger .btn-danger {
        background-color: var(--primary-light);
        border-color: var(--primary-light);
        color: var(--text-light);
        font-weight: 600;
    }

    /* Status e Labels */
    .dark-mode .label {
        font-weight: 600;
        padding: 5px 10px;
    }

    .dark-mode .label-success {
        background-color: var(--success-color);
    }

    .dark-mode .label-danger {
        background-color: var(--danger-color);
    }

    .dark-mode .label-warning {
        background-color: var(--warning-color);
    }

    /* Badges */
    .dark-mode .badge-danger {
        background-color: var(--primary-light);
        font-weight: 600;
    }

    /* Links */
    .dark-mode a {
        color: var(--primary-light);
    }

    .dark-mode a:hover {
        color: var(--hover-color);
        text-decoration: none;
    }

    /* Paginação */
    .dark-mode .pagination > li > a {
        background-color: var(--primary-dark);
        border-color: var(--border-color);
        color: var(--text-light);
    }

    .dark-mode .pagination > .active > a {
        background-color: var(--primary-light);
        border-color: var(--primary-light);
        color: var(--text-light);
        font-weight: 600;
    }

    .dark-mode .pagination > li > a:hover {
        background-color: var(--hover-color);
        border-color: var(--hover-color);
        color: var(--text-light);
    }

    /* Header (tanto para light quanto dark mode) */
    #header {
        background: var(--header-bg) !important;
        border-bottom: 1px solid var(--border-color);
    }

    #header .navbar-nav > li > a {
        color: var(--text-light) !important;
    }

    #header .fa-bell {
        color: var(--text-light) !important;
    }

    /* Tabelas no modo light */
    .table > tbody > tr > td {
        color: #0B2435 !important;
    }

    .table > thead > tr > th {
        color: #0B2435 !important;
        font-weight: 600;
        background-color: #f5f5f5;
    }

    /* Tabelas no modo dark */
    .dark-mode .table > thead > tr > th {
        background-color: #051824 !important;
        color: #00ACAC !important;
        border-bottom: 2px solid #0D3251;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 12px;
    }

    .dark-mode .table > tbody > tr > td {
        color: #0B2435 !important;
        border-color: #0D3251;
        padding: 12px 8px;
    }

    .dark-mode .table-striped > tbody > tr:nth-of-type(odd) {
        background-color: #0A2435;
    }

    .dark-mode .table-striped > tbody > tr:nth-of-type(even) {
        background-color: #051824;
    }

    /* Remove estilos duplicados */
    .dark-mode .table > tbody > tr:hover {
        background-color: rgba(0, 172, 172, 0.15);
    }

    /* Notificações */
    .navbar-nav > li.dropdown > a .fa-bell {
        font-size: 24px;
        color: var(--text-light) !important;
    }

    .badge-danger {
        position: absolute;
        top: 8px;
        right: 5px;
        background-color: var(--primary-light);
        color: white;
        font-size: 11px;
        font-weight: bold;
        padding: 3px 6px;
        border-radius: 10px;
        min-width: 20px;
        text-align: center;
    }

    /* Balance e Price no header */
    .navbar-nav > li.dropdown > a {
        color: var(--text-light) !important;
    }

    /* Theme toggle */
    .theme-toggle {
        color: var(--text-light) !important;
    }

    .theme-toggle:hover {
        color: var(--primary-light) !important;
    }

    /* Estilos para o menu responsivo */
    @media (max-width: 767px) {
        .navbar-toggle {
            display: block;
            float: right;
            margin-right: 15px;
            border-color: #fff;
        }

        .navbar-toggle .icon-bar {
            background-color: #fff;
        }

        .navbar-toggle + .navbar-toggle {
            margin-right: 5px;
        }

        .navbar-collapse {
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: 8px;
        }

        .navbar-collapse.collapse {
            display: none !important;
        }

        .navbar-collapse.collapse.in {
            display: block !important;
        }

        .navbar-nav {
            float: none !important;
            margin: 7.5px -15px;
        }

        .navbar-nav > li {
            float: none;
        }

        .navbar-nav > li > a {
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .navbar-brand {
            padding: 15px;
            height: 50px;
        }

        .navbar-brand img {
            max-height: 20px;
            width: auto;
        }

        #sidebar {
            position: fixed;
            top: 50px;
            bottom: 0;
            left: -220px;
            width: 220px;
            z-index: 1030;
            background: var(--bg-dark);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            transition: all 0.3s ease;
        }

        #sidebar.active {
            left: 0;
        }

        #content {
            margin-left: 0 !important;
        }

        .page-sidebar-toggled #content {
            margin-left: 220px !important;
        }

        .page-sidebar-toggled #sidebar {
            left: 0;
        }

        /* Ajuste para o header fixo */
        #header {
            position: fixed;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1040;
        }

        /* Ajuste para o conteúdo não ficar sob o header */
        #content {
            padding-top: 50px;
        }
    }

    /* Ajustes para o tema escuro no responsivo */
    .dark-mode #sidebar {
        background: var(--bg-dark);
        border-right: 1px solid var(--border-color);
    }

    /* Ajustes para o tema escuro no responsivo */
    .dark-mode .navbar-toggle {
        border-color: var(--text-light);
    }

    .dark-mode .navbar-toggle .icon-bar {
        background-color: var(--text-light);
    }

    .dark-mode .navbar-collapse {
        background-color: var(--header-bg);
        border-color: var(--border-color);
    }

    /* Estilos para os botões do header */
    .header-nav {
        float: right;
        display: flex;
        align-items: center;
    }

    .header-buttons {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-right: 15px;
    }

    .header-buttons > * {
        display: flex;
        align-items: center;
    }

    .header-buttons .dropdown > a,
    .header-buttons .theme-toggle {
        color: var(--text-light);
        font-size: 20px;
        padding: 0;
        position: relative;
    }

    .header-buttons .navbar-toggle {
        margin: 0;
        padding: 9px 10px;
        border: none;
        background: transparent;
    }

    .header-buttons .navbar-toggle .icon-bar {
        background-color: var(--text-light);
        display: block;
        width: 22px;
        height: 2px;
        border-radius: 1px;
        margin-top: 4px;
    }

    .header-buttons .navbar-toggle .icon-bar + .icon-bar {
        margin-top: 4px;
    }

    /* Ajustes responsivos */
    @media (max-width: 767px) {
        .header-buttons {
            margin-right: 5px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-buttons > * {
            display: flex;
            align-items: center;
        }

        .header-buttons .dropdown > a,
        .header-buttons .theme-toggle {
            font-size: 20px;
            padding: 0;
            color: var(--text-light);
        }
    }

    /* Ajustes para o tema escuro */
    .dark-mode .header-nav > a {
        color: var(--text-light);
    }

    .dark-mode .header-nav > a:hover {
        color: var(--primary-light);
    }

    /* Estilos para o dropdown de notificações */
    .notifications-menu {
        width: 300px;
        max-height: 400px;
        overflow-y: auto;
        padding: 0;
        margin: 0;
        border-radius: 4px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        background: var(--bg-light);
        border: 1px solid var(--border-color);
        scrollbar-width: thin;
        scrollbar-color: var(--primary-light) transparent;
    }

    /* Estilização da barra de rolagem para Webkit (Chrome, Safari, etc) */
    .notifications-menu::-webkit-scrollbar {
        width: 6px;
    }

    .notifications-menu::-webkit-scrollbar-track {
        background: transparent;
    }

    .notifications-menu::-webkit-scrollbar-thumb {
        background-color: var(--primary-light);
        border-radius: 3px;
    }

    .notifications-menu::-webkit-scrollbar-thumb:hover {
        background-color: var(--hover-color);
    }

    /* Modo escuro */
    .dark-mode .notifications-menu::-webkit-scrollbar-thumb {
        background-color: var(--primary-light);
    }

    .dark-mode .notifications-menu::-webkit-scrollbar-track {
        background: transparent;
    }

    .notification-item {
        border-bottom: 1px solid var(--border-color);
    }

    .notification-link {
        padding: 12px 15px;
        display: flex;
        align-items: flex-start;
        color: var(--text-light);
        text-decoration: none;
        white-space: normal;
    }

    .notification-link i {
        margin-right: 10px;
        margin-top: 3px;
        flex-shrink: 0;
    }

    .notification-content {
        flex: 1;
        min-width: 0;
    }

    .notification-title {
        font-weight: 600;
        margin-bottom: 3px;
        white-space: normal;
        word-wrap: break-word;
    }

    .notification-message {
        font-size: 12px;
        color: var(--text-muted);
        margin-bottom: 3px;
        white-space: normal;
        word-wrap: break-word;
        line-height: 1.4;
    }

    .notification-time {
        font-size: 11px;
        color: var(--text-muted);
    }

    .notification-item.unread {
        background: rgba(0,172,172,0.1);
    }

    .no-notifications {
        padding: 15px;
        color: var(--text-muted);
    }

    .notification-footer {
        padding: 10px;
        text-align: center;
        border-top: 1px solid var(--border-color);
    }

    .notification-footer .view-all {
        color: var(--primary-light);
        font-size: 12px;
        text-decoration: none;
    }

    .notification-footer .view-all:hover {
        color: var(--hover-color);
    }

    /* Ajuste da posição do dropdown em telas pequenas */
    @media (max-width: 767px) {
        .notifications-menu {
            position: fixed;
            top: 50px;
            right: 0;
            width: 100%;
            max-width: 300px;
            max-height: 80vh; /* Limita a altura em telas pequenas */
            margin: 0;
        }
    }

    /* Ajustes para os ícones na navbar */
    .navbar-nav > li > .dropdown > a,
    .navbar-nav > li > .theme-toggle {
        padding: 15px;
        font-size: 24px;
        color: var(--text-light);
        display: block;
        line-height: 20px;
    }

    .navbar-nav > li > .dropdown > a:hover,
    .navbar-nav > li > .theme-toggle:hover {
        color: var(--primary-light);
        text-decoration: none;
    }

    .navbar-nav > li > .dropdown .badge {
        position: absolute;
        top: 10px;
        right: 5px;
    }

    /* Mantém os ícones na direita no mobile */
    @media (max-width: 767px) {
        .header-nav {
            float: right;
            margin-right: 15px;
        }
        
        .navbar-nav > li > .dropdown,
        .navbar-nav > li > .theme-toggle {
            display: none;
        }
    }

    .navbar-toggle {
        padding: 9px 10px;
        margin: 8px 15px 8px 5px;
        background-color: transparent;
        border: none;
    }

    .navbar-toggle .icon-bar {
        display: block;
        width: 22px;
        height: 2px;
        border-radius: 1px;
        background-color: var(--text-light);
    }

    .navbar-toggle .icon-bar + .icon-bar {
        margin-top: 4px;
    }

    @media (max-width: 767px) {
        .navbar-toggle {
            display: block;
        }
    }
    </style>
</body>
</html>
