<?php

namespace App\Http\Controllers;

use App\User;
use App\Services\AIVerificationService;
use App\Services\FGOLTransferService;
use App\Models\Notification;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller {
	public function __construct() {
		$this->middleware(['auth', 'ckstatus']);
	}

	public function index() {
		$profile = User::find(Auth::id());
		return view('user.profile', compact('profile'));
	}

	public function update(Request $request) {
		$this->validate($request, [
			'photo' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
			'name'  => 'required',
		]);

		$profile = User::find(Auth::id());
		$profile['name'] = $request->name;

		if ($request->hasFile('photo')) {
			if ($profile->photo != 'nopic.png') {
				$path = 'assets/images/profile/' . $profile->photo;
				if (file_exists($path)) unlink($path);
			}
			$profile['photo'] = uniqid() . '.jpg';
			$request->photo->move('assets/images/profile', $profile['photo']);
		}

		$profile['country'] = $request->country;
		$profile['city']    = $request->city;
		$profile['address'] = $request->address;
		$profile['zip']     = $request->zip;
		$profile->save();

		return back()->with('success', 'Profile Updated Successfully!');
	}

	public function verification() {
		$profile = User::find(Auth::id());
		$general = \App\General::first();
		// Verified user with a wallet: confirm with Coinbase that the delegation really exists (the page asks for it otherwise)
		$delegationOk = true;
		if ($profile->verified == 1 && $profile->wallet) {
			$delegationOk = $this->delegationActive($profile, $profile->wallet);
		}
		// Wallet + active delegation + balance still on the platform (e.g. a first attempt failed): send it on-chain now
		if ($delegationOk && $profile->verified == 1 && $profile->wallet && $profile->cdp_user_id) {
			try {
				(new \App\Services\BalanceSweepService())->sweep($profile);
			} catch (\Throwable $e) {
				Log::error('auto sweep (verification page) failed', ['user_id' => $profile->id, 'error' => $e->getMessage()]);
			}
		}
		return view('user.verification', compact('profile', 'general', 'delegationOk'));
	}

	public function updateverification(Request $request) {
		$this->validate($request, [
			'selfie'          => 'nullable|mimes:jpeg,png,jpg,gif,heic,heif,webp|max:30720',
			'identity'        => 'nullable|mimes:jpeg,png,jpg,gif,pdf,heic,heif,webp|max:30720',
			'full_name'       => 'nullable|string|max:191',
			'document_number' => 'nullable|string|max:191',
			'address'         => 'nullable|string|max:191',
			'city'            => 'nullable|string|max:191',
			'state'           => 'nullable|string|max:100',
			'zip'             => 'nullable|string|max:20',
			'country'         => 'nullable|string|max:100',
			'terms_accepted'       => 'nullable|string',
			'newsletter_email'     => 'nullable|string',
			'newsletter_whatsapp'  => 'nullable|string',
		]);

		$profile = User::find(Auth::id());

		// Save selfie if uploaded and not yet approved (or in manual review — allow retry with better photo)
		$newSelfie   = null;
		$newIdentity = null;
		$inManualReview = ($profile->identity_verified == 0 && $profile->selfie_verified == 1);

		if ($request->hasFile('selfie') && ($profile->selfie_verified != 1 || $inManualReview)) {
			if ($profile->selfie && $profile->selfie != 'nopic.png') {
				$old = base_path('assets/images/profile/' . $profile->selfie);
				if (file_exists($old)) unlink($old);
			}
			$filename = uniqid() . '.jpg';
			$destDir  = base_path('assets/images/profile');
			$destPath = $destDir . '/' . $filename;
			$request->selfie->move($destDir, $filename);
			$this->compressImageGD($destPath);
			$profile->selfie          = $filename;
			$profile->selfie_verified = 0; // reset so AI re-evaluates
			$newSelfie                = $filename;
		}

		if ($request->hasFile('identity') && $profile->identity_verified != 1) {
			if ($profile->identity && $profile->identity != 'nopic.png') {
				$old = base_path('assets/images/profile/' . $profile->identity);
				if (file_exists($old)) unlink($old);
			}
			$identityFile = $request->file('identity');
			$filename     = uniqid() . '.jpg';
			$destDir      = base_path('assets/images/profile');
			$destPath     = $destDir . '/' . $filename;

			$ext = strtolower($identityFile->getClientOriginalExtension());
			$mime = $identityFile->getMimeType();
			$isPdf = ($ext === 'pdf' || $mime === 'application/pdf');
			$tmpOrig = $destDir . '/tmp_' . uniqid() . '.' . $ext;
			$identityFile->move($destDir, basename($tmpOrig));
			// Use ImageMagick: convert first page to JPEG (handles PDF, HEIC, JPEG, PNG, WebP, etc.)
			// PDF needs high DPI to be legible; other images just need resize
			if ($isPdf) {
				$imSrc = escapeshellarg($tmpOrig . '[0]');
				$cmd = sprintf(
					'convert -density 250 -auto-orient -resize 2000x2000\> -quality 85 %s JPEG:%s 2>&1',
					$imSrc,
					escapeshellarg($destPath)
				);
			} else {
				$imSrc = escapeshellarg($tmpOrig);
				$cmd = sprintf(
					'convert -auto-orient -resize 1500x1500\> -quality 82 %s JPEG:%s 2>&1',
					$imSrc,
					escapeshellarg($destPath)
				);
			}
			exec($cmd, $out, $code);
			@unlink($tmpOrig);
			if ($code !== 0 || !file_exists($destPath) || filesize($destPath) === 0) {
				return back()->with('alert', 'Não foi possível processar o arquivo. Tente enviar como imagem JPG ou PNG.');
			}
			$profile->identity          = $filename;
			$profile->identity_verified = 0; // reset so AI re-evaluates fresh
			$newIdentity                = $filename;
		}

		// Save new onboarding fields if provided
		if ($request->filled('full_name'))       $profile->name            = $request->full_name;
		if ($request->filled('document_number')) $profile->document_number = $request->document_number;
		if ($request->filled('address'))         $profile->address         = $request->address;
		if ($request->filled('city'))            $profile->city            = $request->city;
		if ($request->filled('state'))           $profile->state           = $request->state;
		if ($request->filled('zip'))             $profile->zip             = $request->zip;
		if ($request->filled('country'))         $profile->country         = $request->country;
		if ($request->terms_accepted === '1') {
			if (empty($profile->terms_accepted_at)) {
				$profile->terms_accepted_at = now();
				$profile->terms_ip          = $request->ip();
				$profile->terms_user_agent  = $request->userAgent();
			}
		}
		$profile->newsletter_email     = $request->newsletter_email     === '1' ? 1 : 0;
		$profile->newsletter_whatsapp  = $request->newsletter_whatsapp  === '1' ? 1 : 0;
		$profile->save();

		// Run AI verification when both images are present and at least one is new,
		// OR when terms are just accepted and user still needs verification
		$termsJustAccepted = $request->terms_accepted === '1' && !empty($profile->terms_accepted_at);
		// A rejected item (2) must be re-uploaded first — a new upload resets it to 0. Never re-evaluate the old, rejected file.
		$nothingRejected = $profile->selfie_verified != 2 && $profile->identity_verified != 2;
		$needsVerification = $profile->verified != 1 && $nothingRejected;
		$aiRan = false;
		// AI verification only runs once the contract is accepted, so nobody is verified before data/address/terms are done
		$termsAccepted = !empty($profile->terms_accepted_at);
		if ($termsAccepted && $nothingRejected && $profile->selfie && $profile->identity && ($newSelfie || $newIdentity || ($termsJustAccepted && $needsVerification))) {
			$aiRan = true;
			$this->runAIVerification($profile, (string) $request->input("full_name", ""), (string) $request->input("document_number", ""));
		}

		if ($profile->verified == 1) {
			return back()->with('success', 'Conta verificada com sucesso! Falta só criar sua carteira GOL: confirme o código enviado ao seu e-mail.');
		}

		if ($newSelfie || $newIdentity) {
			if (!$aiRan && !$termsAccepted) {
				return back()->with('success', 'Arquivo recebido. Continue o cadastro para concluir a verificação.');
			}
			if (!$aiRan) {
				if ($profile->identity_verified == 2) {
					return back()->with('alert', 'Arquivo recebido. Seu documento está rejeitado — envie um novo documento para a verificação ser feita.');
				}
				if ($profile->selfie_verified == 2) {
					return back()->with('alert', 'Arquivo recebido. Sua selfie está rejeitada — envie uma nova selfie para a verificação ser feita.');
				}
			}
			// Read AI result (saved by runAIVerification into $profile->kyc_ai_result)
			$aiResult = json_decode($profile->kyc_ai_result ?? '{}', true) ?? [];
			if ($profile->selfie_verified == 2) {
				$reason = $aiResult['selfie_reason'] ?? 'Envie uma nova foto do seu rosto com boa iluminação.';
				return back()->with('alert', 'Selfie inválida: ' . $reason);
			}
			if ($profile->identity_verified == 2) {
				$reason = $aiResult['identity_reason'] ?? 'Envie uma foto mais clara e legível do documento.';
				return back()->with('alert', 'Documento rejeitado: ' . $reason);
			}
			if ($profile->selfie_verified == 1 && $profile->identity_verified == 0) {
				// Manual review: don't show "Success!" — the step-6 status cards explain the state clearly
				return back();
			}
			return back()->with('success', 'Documentos verificados com sucesso!');
		}

		// Only personal data was saved — no new documents
		if ($profile->identity_verified == 2) {
			return back()->with('alert', 'Seu documento foi rejeitado. Por favor, envie uma nova foto do documento para ser reverificado.');
		}
		if ($profile->selfie_verified == 2) {
			return back()->with('alert', 'Sua selfie foi rejeitada. Por favor, envie uma nova foto.');
		}

		return back()->with('success', 'Dados atualizados. Envie os documentos para concluir a verificação.');
	}

	// CDP returns ISO-8601 ("2027-10-20T12:12:23Z"); MySQL datetime needs "Y-m-d H:i:s"
	private function parseCdpDate(?string $value): ?string {
		if (empty($value)) return null;
		try {
			return \Carbon\Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
		} catch (\Throwable $e) {
			Log::warning('parseCdpDate: invalid date ignored', ['value' => $value]);
			return null;
		}
	}

	public function saveWallet(Request $request) {
		$request->validate([
			'wallet_address'         => 'required|string|min:10|max:500',
			'cdp_user_id'            => 'nullable|string|max:500',
			'delegation_expires_at'  => 'nullable|string|max:100',
			'update_only'            => 'nullable|boolean',
		]);

		$profile = User::find(Auth::id());

		if ($profile->verified != 1) {
			return response()->json(['success' => false, 'message' => 'Conta não verificada.'], 403);
		}

		// update_only: only save cdp_user_id + delegation, wallet already exists
		if ($request->boolean('update_only') && $profile->wallet) {
			if ($request->filled('cdp_user_id')) {
				$profile->cdp_user_id = $request->cdp_user_id;
			}
			if ($request->filled('delegation_expires_at')) {
				$profile->cdp_delegation_expires_at = $this->parseCdpDate($request->delegation_expires_at);
			}
			$profile->save();

			// Authorization just (re)done: confirm with Coinbase, then release any balance waiting on the platform
			$delegated = $this->delegationActive($profile, $profile->wallet);
			$totalSent = 0.0;
			if ($delegated) {
				DB::beginTransaction();
				try {
					[$totalSent,] = $this->transferPlatformBalanceToWallet($profile, $profile->wallet);
					DB::commit();
				} catch (\Throwable $e) {
					DB::rollBack();
					Log::error('saveWallet update_only transfer error', ['user_id' => $profile->id, 'error' => $e->getMessage()]);
				}
			}
			return response()->json([
				'success'   => true,
				'delegated' => $delegated,
				'balance'   => $totalSent,
				'message'   => $delegated ? 'Autorização confirmada.' : 'A Coinbase ainda não confirmou a autorização. Tente novamente.',
			]);
		}

		if ($profile->wallet) {
			return response()->json(['success' => false, 'message' => 'Carteira já cadastrada.'], 400);
		}

		DB::beginTransaction();
		try {
			$walletAddress = $request->wallet_address;

			$profile->wallet = $walletAddress;
			if ($request->filled('cdp_user_id')) {
				$profile->cdp_user_id = $request->cdp_user_id;
			}
			if ($request->filled('delegation_expires_at')) {
				$profile->cdp_delegation_expires_at = $this->parseCdpDate($request->delegation_expires_at);
			}
			$profile->save();

			[$totalSent, $txHash] = $this->transferPlatformBalanceToWallet($profile, $walletAddress);

			DB::commit();

			$delegated   = $this->delegationActive($profile, $walletAddress);
			$transferMsg = $txHash ? ' — TX: ' . substr($txHash, 0, 16) . '...' : '';
			$notif          = new Notification();
			$notif->user_id = $profile->id;
			$notif->title   = 'Carteira GOL Criada';
			$notif->message = $delegated
				? number_format($totalSent, 2) . ' tokens GOL enviados para sua carteira: ' . substr($walletAddress, 0, 10) . '...' . $transferMsg
				: 'Carteira registrada, mas falta confirmar a autorização. Abra Verificação de Conta e conclua a autorização — seus tokens serão enviados em seguida.';
			$notif->type    = $delegated ? 'success' : 'warning';
			$notif->read    = false;
			$notif->save();

			return response()->json([
				'success'   => true,
				'delegated' => $delegated,
				'message'   => $delegated ? 'Carteira registrada e tokens transferidos com sucesso!' : 'Carteira registrada. Falta confirmar a autorização.',
				'balance'   => $totalSent,
				'address'   => $walletAddress,
			]);
		} catch (\Throwable $e) {
			DB::rollBack();
			Log::error('saveWallet error', ['error' => $e->getMessage()]);
			return response()->json(['success' => false, 'message' => 'Erro interno. Tente novamente.'], 500);
		}
	}


	public function walletInitiate(Request $request) {
		$profile = \App\User::find(\Auth::id());
		if ($profile->verified != 1) {
			return response()->json(['ok' => false, 'error' => 'Conta não verificada.'], 403);
		}
		if ($profile->wallet) {
			return response()->json(['ok' => true, 'already' => true, 'address' => $profile->wallet]);
		}
		$cdp    = new \App\Services\CDPService();
		$result = $cdp->initiateWalletOtp($profile->email);
		if ($result['ok']) {
			return response()->json(['ok' => true, 'message' => 'Código enviado para ' . $profile->email]);
		}
		\Log::warning('walletInitiate CDP error', $result);
		return response()->json(['ok' => false, 'error' => $result['error'] ?? 'Erro ao enviar código.'], 500);
	}

	public function walletConfirm(Request $request) {
		$request->validate(['otp' => 'required|string|min:4|max:10']);
		$profile = \App\User::find(\Auth::id());
		if ($profile->verified != 1) {
			return response()->json(['ok' => false, 'error' => 'Conta não verificada.'], 403);
		}
		if ($profile->wallet) {
			return response()->json(['ok' => true, 'address' => $profile->wallet]);
		}
		$cdp    = new \App\Services\CDPService();
		$result = $cdp->confirmWalletOtp($profile->email, $request->otp);
		if (!empty($result['address'])) {
			$walletAddress = $result['address'];
			DB::beginTransaction();
			try {
				$profile->wallet = $walletAddress;
				if (!empty($result['user_id'])) $profile->cdp_user_id = $result['user_id'];
				$profile->save();

				[$totalSent, $txHash] = $this->transferPlatformBalanceToWallet($profile, $walletAddress);

				DB::commit();
			} catch (\Throwable $e) {
				DB::rollBack();
				\Log::error('walletConfirm transfer error', ['error' => $e->getMessage()]);
				$totalSent = 0;
				$txHash    = null;
			}

			$notif = new \App\Notification();
			$notif->user_id = $profile->id;
			$notif->title   = 'Carteira GOL Criada';
			$notif->message = ($totalSent > 0 ? number_format($totalSent, 2) . ' FGOL enviados para ' : 'Carteira criada: ') . substr($walletAddress, 0, 10) . '...';
			$notif->type    = 'success';
			$notif->read    = false;
			$notif->save();
			return response()->json(['ok' => true, 'address' => $walletAddress]);
		}
		\Log::warning('walletConfirm CDP error', $result);
		return response()->json(['ok' => false, 'error' => $result['error'] ?? 'Código inválido ou expirado.'], 422);
	}


	public function walletGenerate(Request $request) {
		$profile = \App\User::find(\Auth::id());
		if (!$profile || $profile->verified != 1) {
			return response()->json(['ok' => false, 'error' => 'Conta não verificada.'], 403);
		}
		if ($profile->wallet) {
			return response()->json(['ok' => true, 'address' => $profile->wallet]);
		}
		try {
			$address = $this->generateEthAddress();
			DB::beginTransaction();
			$profile->wallet = $address;
			$profile->save();

			[$totalSent,] = $this->transferPlatformBalanceToWallet($profile, $address);

			DB::commit();
			\Log::info('ETH wallet generated for user', ['user_id' => $profile->id, 'address' => $address, 'balance_sent' => $totalSent]);
			return response()->json(['ok' => true, 'address' => $address]);
		} catch (\Throwable $e) {
			DB::rollBack();
			\Log::error('walletGenerate exception', ['user_id' => $profile->id, 'error' => $e->getMessage()]);
			return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
		}
	}


	/**
	 * Zero all internal address balances and users.balance, write logs, then
	 * trigger FGOLTransferService to send the total on-chain from the vault.
	 * Must be called inside an open DB transaction.
	 * Returns [float $totalSent, string|null $txHash].
	 */
	private function compressImageGD(string $path, int $maxPx = 1500, int $quality = 80): void
	{
		if (!file_exists($path)) return;

		$tmp = $path . '_im_tmp.jpg';
		$cmd = sprintf(
			'convert -auto-orient -resize %1$dx%1$d\> -quality %2$d %3$s JPEG:%4$s 2>&1',
			$maxPx,
			$quality,
			escapeshellarg($path),
			escapeshellarg($tmp)
		);
		exec($cmd, $out, $code);

		if ($code === 0 && file_exists($tmp) && filesize($tmp) > 0) {
			rename($tmp, $path);
		} else {
			@unlink($tmp);
		}
	}

	private function delegationActive(User $user, string $walletAddress): bool
	{
		$check = (new \App\Services\CDPService())->hasActiveDelegation((string) $user->cdp_user_id, $walletAddress);
		if (!$check['active']) {
			Log::warning('Delegação CDP não confirmada', ['user_id' => $user->id, 'wallet' => $walletAddress, 'reason' => $check['reason']]);
		}
		return $check['active'];
	}

	private function transferPlatformBalanceToWallet(User $user, string $walletAddress): array
	{
		// Single implementation lives in BalanceSweepService (ledger amount, delegation check, row lock, no debit on failure)
		$r = (new \App\Services\BalanceSweepService())->sweep($user, $walletAddress);
		return [$r['sent'], $r['tx']];
	}


	public function sendTokens(Request $request) {
		$request->validate([
			'to_address' => 'required|string|regex:/^0x[0-9a-fA-F]{40}$/',
			'amount'     => 'required|numeric|min:0.000001',
		]);

		$profile = User::find(Auth::id());

		if ($profile->verified != 1 || !$profile->wallet) {
			return response()->json(['success' => false, 'error' => 'Conta não verificada ou carteira não criada.'], 403);
		}

		$amount = (float) $request->amount;
		$to     = $request->to_address;

		$result = (new FGOLTransferService())->transfer($to, $amount);

		if (!$result['success']) {
			return response()->json(['success' => false, 'error' => $result['error'] ?? 'Erro na transferência.'], 500);
		}

		return response()->json([
			'success' => true,
			'txHash'  => $result['txHash'] ?? null,
			'queued'  => $result['queued'] ?? false,
		]);
	}

	public function sendFGOL(Request $request) {
		$request->validate([
			'to_address' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{40}$/'],
			'amount'     => 'required|numeric|min:0.01',
			'code'       => 'required|string|min:6|max:6',
		]);

		$profile = User::find(Auth::id());

		if ($profile->verified != 1 || !$profile->wallet) {
			return response()->json(['ok' => false, 'error' => 'Conta não verificada ou carteira não criada.'], 403);
		}

		if (empty($profile->cdp_user_id)) {
			return response()->json(['ok' => false, 'error' => 'Autorização de carteira pendente. Confirme sua identidade primeiro.'], 403);
		}

		// 2FA is mandatory for on-chain sends
		if ($profile->tauth != 1 || empty($profile->secretcode) || $profile->secretcode === '0') {
			return response()->json(['ok' => false, 'error' => 'Autenticação em dois fatores não ativada. Ative o 2FA antes de enviar tokens.'], 403);
		}

		$ga = new \App\Lib\GoogleAuthenticator();
		if (!$ga->verifyCode($profile->secretcode, trim($request->code), 1)) {
			return response()->json(['ok' => false, 'error' => 'Código 2FA inválido. Tente novamente.'], 422);
		}

		$to     = $request->to_address; // user-specified destination
		$amount = (float) $request->amount;

		// Source is always the user's own CDP wallet — the on-chain balance is authoritative
		try {
			$result = (new \App\Services\CDPService())->sendFgolFromUserCdp(
				$profile->cdp_user_id,
				$profile->wallet,
				$to,
				$amount
			);

			DB::table('transaction_logs')->insert([
				'user_id'        => $profile->id,
				'wallet_address' => $to,
				'amount'         => $amount,
				'status'         => 'success',
				'response'       => 'cdp_send txHash:' . $result['txHash'],
				'created_at'     => now(),
				'updated_at'     => now(),
			]);

			$notif          = new Notification();
			$notif->user_id = $profile->id;
			$notif->title   = 'FGOL Enviado';
			$notif->message = number_format($amount, 2) . ' FGOL enviados para ' . substr($to, 0, 10) . '...';
			$notif->type    = 'success';
			$notif->read    = false;
			$notif->save();

			return response()->json([
				'ok'     => true,
				'txHash' => $result['txHash'],
			]);
		} catch (\Throwable $e) {
			Log::error('sendFGOL error', ['user_id' => $profile->id, 'error' => $e->getMessage()]);
			return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
		}
	}

	public function saveDelegation(Request $request) {
		$profile = \App\User::find(Auth::id());
		if ($profile->verified != 1) {
			return response()->json(['success' => false, 'message' => 'Conta não verificada.'], 403);
		}
		$profile->wallet_delegated_at = now();
		$profile->save();
		Log::info('Wallet delegation confirmed', ['user_id' => $profile->id]);
		return response()->json(['success' => true]);
	}

	private function runAIVerification(User $profile, ?string $fullName = "", ?string $documentNumber = ""): void {
		try {
			$ai     = new AIVerificationService();
			// Name/number come from the form when present, otherwise from the saved profile (steps 2/3 don't send them)
			// Until the personal-data step is filled (document number saved) the profile name is just the signup name,
			// so don't compare it with the document yet.
			$dataDone    = trim((string) $profile->document_number) !== '';
			$nameToCheck = trim((string) $fullName) !== '' ? (string) $fullName : ($dataDone ? (string) $profile->name : '');
			$docToCheck  = trim((string) $documentNumber) !== '' ? (string) $documentNumber : (string) $profile->document_number;
			$result = $ai->verifyDocuments($profile->selfie ?? '', $profile->identity ?? '', $nameToCheck, $docToCheck);

			$oldSelfie   = $profile->selfie_verified;
			$oldIdentity = $profile->identity_verified;

			// Only pending items (0) are evaluated — approved (1) and rejected (2, by AI or admin) are never overwritten
			if ((int) $profile->selfie_verified === 0) {
				$profile->selfie_verified = $result['selfie'];
			}
			if ((int) $profile->identity_verified === 0) {
				$profile->identity_verified = $result['identity'];
			}

			if ($profile->selfie_verified == 1 && $profile->identity_verified == 1) {
				$profile->verified = 1;
			} elseif ($profile->selfie_verified == 2 || $profile->identity_verified == 2) {
				$profile->verified = 0;
			}

			$profile->save();

			// When account becomes verified: ensure wallet exists and drain DB balance
			if ($profile->verified == 1) {
				$this->handleWalletOnVerification($profile);
			}

			// Notify user of the outcome
			if ($oldSelfie != $profile->selfie_verified || $oldIdentity != $profile->identity_verified) {
				$this->sendVerificationNotification($profile, $result);
			}

			$profile->kyc_ai_result = json_encode($result);
			$profile->saveQuietly();
			Log::info('AI Verification complete', [
				'user_id'  => $profile->id,
				'selfie'   => $profile->selfie_verified,
				'identity' => $profile->identity_verified,
				'verified' => $profile->verified,
			]);
		} catch (\Throwable $e) {
			Log::error('runAIVerification failed', ['error' => $e->getMessage()]);
		}
	}

	/**
	 * Called whenever a user's account reaches verified=1.
	 * - If they already have a valid EVM wallet: just drain DB balance to on-chain.
	 * - If they have no wallet: create one via CDP (server MPC), then drain DB balance.
	 */
	private function handleWalletOnVerification(User $profile): void
	{
		try {
			// The cdp_user_id is proof that CDP confirmed this wallet via OTP.
			// Without it, the user has not gone through the CDP wallet creation flow.
			$cdpConfirmed = !empty($profile->cdp_user_id)
				&& !empty($profile->wallet)
				&& preg_match('/^0x[0-9a-fA-F]{40}$/', $profile->wallet);

			if (!$cdpConfirmed) {
				Log::info('handleWalletOnVerification: no CDP-confirmed wallet, skipping', [
					'user_id'      => $profile->id,
					'wallet'       => $profile->wallet,
					'cdp_user_id'  => $profile->cdp_user_id,
				]);
				return;
			}

			$walletAddress = $profile->wallet;

			DB::beginTransaction();
			[$totalSent, $txHash] = $this->transferPlatformBalanceToWallet($profile, $walletAddress);
			DB::commit();

			Log::info('handleWalletOnVerification: balance transferred', [
				'user_id'    => $profile->id,
				'wallet'     => $walletAddress,
				'totalSent'  => $totalSent,
				'txHash'     => $txHash,
			]);
		} catch (\Throwable $e) {
			DB::rollBack();
			Log::error('handleWalletOnVerification failed', [
				'user_id' => $profile->id, 'error' => $e->getMessage(),
			]);
		}
	}

	private function generateEthAddress(): string
	{
		$pkey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
		if (!$pkey) throw new \RuntimeException('secp256k1 not available: ' . openssl_error_string());
		$details = openssl_pkey_get_details($pkey);
		$x = str_pad(bin2hex($details['ec']['x']), 64, '0', STR_PAD_LEFT);
		$y = str_pad(bin2hex($details['ec']['y']), 64, '0', STR_PAD_LEFT);
		$keccak = \kornrunner\Keccak::hash(hex2bin($x . $y), 256);
		return '0x' . strtolower(substr($keccak, -40));
	}


	private function sendVerificationNotification(User $profile, array $result): void {
		try {
			if ($profile->verified == 1) {
				$notif          = new Notification();
				$notif->user_id = $profile->id;
				$notif->title   = 'Conta Verificada com Sucesso!';
				$notif->message = 'Sua identidade foi verificada! Falta um passo: acesse Verificação de Conta e crie sua carteira GOL com o código enviado ao seu e-mail. Bem-vindo à GOLDOFIR!';
				$notif->type    = 'success';
				$notif->read    = false;
				$notif->save();
				return;
			}

			if ($profile->selfie_verified == 2) {
				$notif          = new Notification();
				$notif->user_id = $profile->id;
				$notif->title   = 'Selfie Rejeitada';
				$notif->message = $result['selfie_reason'] ?? 'Sua selfie não passou na verificação. Por favor, envie uma nova foto.';
				$notif->type    = 'danger';
				$notif->read    = false;
				$notif->save();
			}

			if ($profile->identity_verified == 2) {
				$notif          = new Notification();
				$notif->user_id = $profile->id;
				$notif->title   = 'Documento Rejeitado';
				$notif->message = $result['identity_reason'] ?? 'Seu documento não passou na verificação. Por favor, envie um novo.';
				$notif->type    = 'danger';
				$notif->read    = false;
				$notif->save();
			}

			// Manual review: selfie approved but face didn't match clearly → notify user
			if ($profile->selfie_verified == 1 && $profile->identity_verified == 0 && !empty($result['manual_review'])) {
				$notif          = new Notification();
				$notif->user_id = $profile->id;
				$notif->title   = 'Verificação em Revisão Manual';
				$notif->message = 'Sua selfie foi aprovada, mas o documento precisa de revisão manual: ' . ($result['identity_reason'] ?? 'a equipe vai conferir os dados.') . ' Você pode reenviar novas fotos ou aguardar a análise da equipe.';
				$notif->type    = 'warning';
				$notif->read    = false;
				$notif->save();
			}
		} catch (\Throwable $e) {
			Log::error('sendVerificationNotification failed', ['error' => $e->getMessage()]);
		}
	}
}
