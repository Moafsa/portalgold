<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\General;
use App\User;
use App\Services\SmtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailQueueController extends Controller
{
    public function __construct()
    {
        $this->middleware('admin');
    }

    // ─── Admin: list campaigns ────────────────────────────────────────────────

    public function index()
    {
        $campaigns = DB::table('email_campaigns')->orderByDesc('created_at')->paginate(20);
        $smtpOk    = (new SmtpService())->isConfigured();
        return view('admin.email.campaigns', compact('campaigns', 'smtpOk'));
    }

    // ─── Admin: create campaign form ─────────────────────────────────────────

    public function create()
    {
        $totalUsers        = User::count();
        $verifiedUsers     = User::where('verified', 1)->count();
        $unverifiedUsers   = $totalUsers - $verifiedUsers;
        $withBalanceUsers  = DB::table('addresses')->where('balance', '>', 0)->distinct('user_id')->count('user_id');
        $emailVerifiedUsers= User::where('emailv', 1)->count();
        $templates         = DB::table('email_templates')->orderBy('name')->get();
        return view('admin.email.create', compact(
            'totalUsers', 'verifiedUsers', 'unverifiedUsers',
            'withBalanceUsers', 'emailVerifiedUsers', 'templates'
        ));
    }

    // ─── Admin: submit campaign → queue all emails ───────────────────────────

    public function store(Request $request)
    {
        $isEvent = (bool)$request->is_event;

        $rules = [
            'subject'   => 'required|string|max:500',
            'body'      => 'required|string',
            'target'    => 'required|in:all,verified,unverified,with_balance,without_balance,email_verified,specific',
            'rate_hour' => 'required|integer|min:1|max:1000',
        ];
        if ($request->target === 'specific') {
            $rules['specific_emails'] = 'required|string';
        }
        if ($isEvent) {
            $rules['event_title']        = 'required|string|max:500';
            $rules['event_at']           = 'required|date';
            $rules['event_duration_min'] = 'required|integer|min:1|max:1440';
            $rules['event_url']          = 'required|url|max:1000';
        }
        $request->validate($rules);

        // Build recipient list
        if ($request->target === 'specific') {
            // Parse comma/newline-separated emails entered by admin
            $rawEmails = preg_split('/[\s,;]+/', trim($request->specific_emails));
            $rawEmails = array_filter(array_map('trim', $rawEmails));
            $validEmails = array_values(array_filter($rawEmails, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));

            if (empty($validEmails)) {
                return back()->withInput()->with('error', 'Nenhum endereço de e-mail válido informado.');
            }

            // Try to match each email to a user; fallback to anonymous entry
            $users = collect($validEmails)->map(function ($email) {
                $u = User::where('email', $email)->select('id', 'email', 'name')->first();
                return $u ?? (object)['id' => null, 'email' => $email, 'name' => explode('@', $email)[0]];
            });
        } else {
            $query = User::query()->where('status', 1);
            if ($request->target === 'verified')        $query->where('verified', 1);
            if ($request->target === 'unverified')      $query->where('verified', 0);
            if ($request->target === 'email_verified')  $query->where('emailv', 1);
            if ($request->target === 'with_balance') {
                $query->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('addresses')
                      ->whereColumn('addresses.user_id', 'users.id')
                      ->where('addresses.balance', '>', 0);
                });
            }
            if ($request->target === 'without_balance') {
                $query->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from('addresses')
                      ->whereColumn('addresses.user_id', 'users.id')
                      ->where('addresses.balance', '>', 0);
                });
            }
            $users = $query->select('id', 'email', 'name')->get();
        }

        if ($users->isEmpty()) {
            return back()->withInput()->with('error', 'Nenhum usuário encontrado para o segmento selecionado.');
        }

        $campaignId  = (string)Str::uuid();
        $ratePerHour = (int)$request->rate_hour;
        $delaySeconds = 3600 / $ratePerHour;

        // Register campaign
        DB::table('email_campaigns')->insert([
            'id'               => $campaignId,
            'subject'          => $request->subject,
            'body'             => $request->body,
            'target'           => $request->target,
            'total'            => $users->count(),
            'rate_hour'        => $ratePerHour,
            'status'           => 'queued',
            'is_event'         => $isEvent ? 1 : 0,
            'event_title'      => $isEvent ? $request->event_title : null,
            'event_at'         => $isEvent ? \Carbon\Carbon::parse($request->event_at)->utc() : null,
            'event_duration_min' => $isEvent ? (int)$request->event_duration_min : null,
            'event_url'        => $isEvent ? $request->event_url : null,
            'created_by'       => auth()->id(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Queue individual emails with staggered scheduled_at
        $now = now();
        $rows = [];
        foreach ($users as $i => $u) {
            $sendAt = $now->copy()->addSeconds((int)($i * $delaySeconds));
            $rows[] = [
                'campaign_id'  => $campaignId,
                'user_id'      => $u->id,
                'to_email'     => $u->email,
                'to_name'      => $u->name,
                'subject'      => $request->subject,
                'body'         => $this->personalizeBody($request->body, $u, $isEvent ? ['url' => $request->event_url, 'title' => $request->event_title] : null),
                'status'       => 'pending',
                'scheduled_at' => $sendAt,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];

            // Insert in batches of 500
            if (count($rows) >= 500) {
                DB::table('email_queue')->insert($rows);
                $rows = [];
            }
        }
        if (!empty($rows)) {
            DB::table('email_queue')->insert($rows);
        }

        Log::info("Email campaign queued", [
            'campaign_id' => $campaignId,
            'total'       => $users->count(),
            'rate_hour'   => $ratePerHour,
        ]);

        // Dispatch first batch immediately so emails don't wait for the 10-min scheduler
        try {
            $this->process();
        } catch (\Throwable $e) {
            Log::error('Immediate process failed: ' . $e->getMessage());
        }

        $fusername = request()->route('fusername');
        return redirect()->route('admin.email.campaigns', ['fusername' => $fusername])->with(
            'success',
            "Campanha criada! {$users->count()} e-mails agendados a {$ratePerHour}/hora. Tempo estimado: " .
            $this->estimatedTime($users->count(), $ratePerHour)
        );
    }

    // ─── Cron/scheduler: process pending emails ───────────────────────────────

    public function process()
    {
        $smtp = new SmtpService();
        if (!$smtp->isConfigured()) {
            return response()->json(['error' => 'SMTP não configurado.'], 422);
        }

        // Preload campaigns that are events so we can build ICS per email
        $eventCampaigns = DB::table('email_campaigns')
            ->where('is_event', 1)
            ->whereIn('status', ['queued', 'running'])
            ->get()
            ->keyBy('id');

        // Find pending emails scheduled up to now
        $pending = DB::table('email_queue')
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get();

        $sent = 0;
        $failed = 0;

        // Organizer info from SMTP settings (loaded once)
        $general = \App\General::first();
        $organizerEmail = $general->smtp_from_address ?? 'noreply@goldofir.me';
        $organizerName  = $general->smtp_from_name    ?? 'GOLDOFIR';

        foreach ($pending as $item) {
            // Mark as processing (optimistic lock via status update)
            $updated = DB::table('email_queue')
                ->where('id', $item->id)
                ->where('status', 'pending')
                ->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);

            if (!$updated) continue; // Another process grabbed it

            // Build ICS if campaign is an event
            $icsContent = null;
            if (isset($eventCampaigns[$item->campaign_id])) {
                $c = $eventCampaigns[$item->campaign_id];
                $icsContent = SmtpService::buildIcs(
                    uid: $item->campaign_id . '-' . $item->id . '@goldofir.me',
                    title: $c->event_title,
                    eventAt: $c->event_at,
                    durationMin: (int)$c->event_duration_min,
                    url: $c->event_url,
                    organizerEmail: $organizerEmail,
                    organizerName: $organizerName,
                    attendeeEmail: $item->to_email,
                    attendeeName: $item->to_name ?? '',
                );
            }

            // Wrap body in Etemplate (send_email() does this itself; here we do it explicitly)
            $body = $item->body;
            if (isset($eventCampaigns[$item->campaign_id])) {
                $ec   = $eventCampaigns[$item->campaign_id];
                $body = $this->applyEventVars($body, (string) $ec->event_url, (string) $ec->event_title);
            }
            try {
                $tpl = \App\Etemplate::first();
                if ($tpl && !empty($tpl->emessage)) {
                    $body = str_replace(
                        ['{{message}}', '{{name}}'],
                        [$body, $item->to_name ?? ''],
                        $tpl->emessage
                    );
                }
            } catch (\Throwable $_) {}

            $result = $smtp->send($item->to_email, $item->to_name ?? '', $item->subject, $body, $icsContent);

            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                DB::table('email_queue')->where('id', $item->id)->update([
                    'status'     => 'failed',
                    'error'      => substr($result['error'], 0, 500),
                    'updated_at' => now(),
                ]);
            }

            // Update campaign counters
            DB::table('email_campaigns')
                ->where('id', $item->campaign_id)
                ->increment($result['success'] ? 'sent' : 'failed');
        }

        // Mark campaigns as done when all emails processed
        DB::statement("
            UPDATE email_campaigns ec
            SET status = 'done', updated_at = NOW()
            WHERE status IN ('queued','running')
              AND (SELECT COUNT(*) FROM email_queue WHERE campaign_id = ec.id AND status = 'pending') = 0
        ");

        return response()->json(['sent' => $sent, 'failed' => $failed]);
    }

    // ─── SMTP test ────────────────────────────────────────────────────────────

    public function testSmtp(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $result = (new SmtpService())->test($request->email);
        if ($result['success']) {
            return back()->with('success', 'E-mail de teste enviado para ' . $request->email);
        }
        return back()->with('error', 'Falha no envio: ' . $result['error']);
    }

    // ─── Campaign details ─────────────────────────────────────────────────────

    public function show(string $fusername, string $id)
    {
        $campaign = DB::table('email_campaigns')->where('id', $id)->first();
        if (!$campaign) abort(404);
        $emails = DB::table('email_queue')
            ->where('campaign_id', $id)
            ->orderBy('scheduled_at')
            ->paginate(50);
        return view('admin.email.show', compact('campaign', 'emails'));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function personalizeBody(string $body, $user, ?array $event = null): string
    {
        $body = str_replace(
            ['{{name}}', '{{email}}', '{{username}}'],
            [$user->name, $user->email, $user->username ?? ''],
            $body
        );
        return $event ? $this->applyEventVars($body, $event['url'] ?? '', $event['title'] ?? '') : $body;
    }

    /**
     * Event/call placeholders, filled from the "convite para call" fields of the campaign:
     *   {{event_url}} (aliases {{link_live}}, {{link}}), {{event_url_enc}} (URL-encoded, for query strings) and {{event_title}}.
     * The old literal "[LINK DA LIVE]" (also URL-encoded, e.g. inside a calendar link) is replaced too.
     */
    private function applyEventVars(string $body, string $url, string $title): string
    {
        if ($url === '') return $body;
        $htmlUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $encUrl  = rawurlencode($url);

        // URL-encoded placeholder first (used inside query strings such as the Google Calendar "location")
        $body = str_replace(
            ['%5BLINK%20DA%20LIVE%5D', '%5Blink%20da%20live%5D', '[LINK%20DA%20LIVE]'],
            $encUrl,
            $body
        );
        $body = str_ireplace('[LINK DA LIVE]', $htmlUrl, $body);

        return str_replace(
            ['{{event_url_enc}}', '{{event_url}}', '{{link_live}}', '{{link}}', '{{event_title}}'],
            [$encUrl, $htmlUrl, $htmlUrl, $htmlUrl, htmlspecialchars($title, ENT_QUOTES, 'UTF-8')],
            $body
        );
    }

    private function estimatedTime(int $total, int $perHour): string
    {
        $hours = $total / $perHour;
        if ($hours < 1) return round($hours * 60) . ' minutos';
        return round($hours, 1) . ' horas';
    }
}
