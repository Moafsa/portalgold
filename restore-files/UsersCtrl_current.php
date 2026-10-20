<?php

namespace App\Http\Controllers;

use App\Sell;
use App\Subscribe;
use App\Transaction;
use App\User;
use Auth;
// use Barryvdh\DomPDF\Facade\Pdf; // replaced by wkhtmltopdf
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Jobs\SendBulkEmailJob;

class UsersController extends Controller {
	public function __construct() {
		$this->middleware('admin');
	}

	public function index() {
		$users = User::orderBy('id', 'desc')->paginate(10);
		return view('admin.users.index', compact('users'));
	}

	public function sellLog() {
		$sells = Sell::where('status', 1)->orderBy('id', 'desc')->paginate(10);
		return view('admin.sell', compact('sells'));
	}

	public function transactionLog() {
		$trans = Transaction::orderBy('id', 'desc')->paginate(20);
		return view('admin.transaction', compact('trans'));
	}

	public function userSearch(Request $request) {
		$this->validate($request,
			[
				'search' => 'required',
			]);

		$users = User::where('username', 'like', '%' . $request->search . '%')->orWhere('email', 'like', '%' . $request->search . '%')->orWhere('name', 'like', '%' . $request->search . '%')->get();

		return view('admin.users.search', compact('users'));

	}

	public function subscribers() {
		$subs = Subscribe::all();
		return view('admin.users.subscribers', compact('subs'));
	}

	public function subsEmail(Request $request) {
		$this->validate($request,
			[
				'subject' => 'required',
				'emailMessage' => 'required',
			]);

		$subs = Subscribe::all();

		foreach ($subs as $user) {

			$to = $user->email;
			$name = $user->name;
			$subject = $request->subject;
			$message = $request->emailMessage;

			send_email($to, $name, $subject, $message);
		}

		return back()->withSuccess('Mail Sent Successfuly');
	}

	public function single(Request $request, $username, $id) {
		$user = User::findOrFail($id);
		$address = $request->address ?: $user->address()->first()->address;
		$trans = Transaction::where('receiver', $address)->orWhere('sender', $address)->orWhere('trxid', $address)->orWhere('amount', $address)->orderBy('created_at', 'DESC')->paginate(20);
		return view('admin.users.single', ['user' => $user, 'trans' => $trans, 'addressItem' => $address]);
	}

	public function email($username, $id) {
		$user = User::findorFail($id);
		return view('admin.users.email', compact('user'));
	}

	public function sendemail(Request $request) {
		$this->validate($request,
			[
				'emailto' => 'required|email',
				'reciver' => 'required',
				'subject' => 'required',
				'emailMessage' => 'required',
			]);
		$to = $request->emailto;
		$name = $request->reciver;
		$subject = $request->subject;
		$message = $request->emailMessage;
		
	
		send_email($to, $name, $subject, $message);
	

		return back()->withSuccess('Mail Sent Successfuly');

	}

	public function broadcast() {
		return view('admin.users.broadcast');
	}

	public function broadcastemail(Request $request) {
		$this->validate($request,
			[
				'subject' => 'required',
				'emailMessage' => 'required',
			]);

		$users = User::where('status', '1')->get();

		foreach ($users as $user) {
			SendBulkEmailJob::dispatch(
				$user->email,
				$user->name,
				$request->subject,
				str_replace('{{name}}', $user->name, $request->emailMessage)
			)->delay(now()->addSeconds(rand(1, 10))); // Adiciona delay aleatório inicial
		}

		return back()->withSuccess('Emails enfileirados para envio');
	}

	public function userPasschange(Request $request, $username, $id) {
		$user = User::find($id);

		$this->validate($request,
			[
				'password' => 'required|string|min:6|confirmed',
			]);
		if ($request->password == $request->password_confirmation) {
			$user->password = bcrypt($request->password);
			$user->save();

			$msg = 'Password Changed By Admin. New Password is: ' . $request->password;
            
			send_email($user->email, $user->username, 'Password Changed', $msg);
        
			$sms = 'Password Changed By Admin. New Password is: ' . $request->password;
			send_sms($user->mobile, $sms);

			return back()->with('success', 'Password Changed');
		} else {
			return back()->with('alert', 'Password Not Matched');
		}
	}

	public function statupdate(Request $request, $username, $id) {
		$user = User::find($id);

		$this->validate($request,
			[
				'name' => 'required|string|max:255',
				'email' => 'required|string|max:255',
				'mobile' => 'required|string|max:255',
			]);

		try {
			$user->name = $request->name;
			$user->mobile = $request->mobile;
			$user->email = $request->email;
			$user->status = $request->status == "1" ? 1 : 0;
			$user->emailv = $request->emailv == "1" ? 1 : 0;
			$user->smsv = $request->smsv == "1" ? 1 : 0;
			$user->tauth = $request->tauth == "1" ? 1 : 0;

			// Não atualiza os status de verificação se não foram enviados no request
			if (Schema::hasColumn('users', 'selfie_verified') && isset($request->selfie_verified)) {
				$user->selfie_verified = $request->selfie_verified;
			}
			if (Schema::hasColumn('users', 'identity_verified') && isset($request->identity_verified)) {
				$user->identity_verified = $request->identity_verified;
			}

			// Atualiza o status geral de verificação
			if ($user->selfie_verified == 1 && $user->identity_verified == 1) {
				$user->verified = 1;
				\Log::info('Usuário marcado como verificado', [
					'user_id' => $user->id,
					'selfie_verified' => $user->selfie_verified,
					'identity_verified' => $user->identity_verified,
					'verified' => $user->verified
				]);
			} else {
				$user->verified = 0;
				\Log::info('Usuário marcado como não verificado', [
					'user_id' => $user->id,
					'selfie_verified' => $user->selfie_verified,
					'identity_verified' => $user->identity_verified,
					'verified' => $user->verified
				]);
			}

			$user->save();

			$msg = 'Your Profile Updated by Admin';
			send_email($user->email, $user->username, 'Profile Updated', $msg);
			$sms = 'Your Profile Updated by Admin';
			send_sms($user->mobile, $sms);

			return back()->withSuccess('User Profile Updated Successfully');
		} catch (\Exception $e) {
			\Log::error('Error updating user status: ' . $e->getMessage());
			return back()->withError('Error updating user profile. Please try again.');
		}
	}

	public function verifiedUsers() {
		$pending = \App\User::where("verified", 0)
			->where(function($q) {
				$q->whereNotNull("selfie")->where("selfie","!=","")
				->orWhereNotNull("identity")->where("identity","!=","");
			})
			->whereNotNull("terms_accepted_at")
			// Rejected items wait for the user to resubmit — only items awaiting review are listed
			->where(function($q) { $q->whereNull("selfie_verified")->orWhere("selfie_verified", "!=", 2); })
			->where(function($q) { $q->whereNull("identity_verified")->orWhere("identity_verified", "!=", 2); })
			->orderBy("terms_accepted_at", "desc")
			->get();
		$users = \App\User::where('verified', 1)
			->orderBy('updated_at', 'desc')
			->paginate(20);
		$onchain = [];
		try {
			$onchain = \App\Services\FGOLTransferService::onchainBalances($users->pluck('wallet')->filter()->all());
		} catch (\Throwable $e) {
			\Log::warning('verifiedUsers: on-chain balances unavailable', ['error' => $e->getMessage()]);
		}
		return view('admin.users.verified', compact('users', 'pending', 'onchain'));
	}

	public function banusers() {
		$users = User::where('status', '0')->orderBy('id', 'desc')->paginate(10);
		return view('admin.users.banned', compact('users'));
	}

	public function balancesIncrease(Request $request, $username, $id){
		$user = User::find($id);
		$balance = 0;
		$credited = 0;
		foreach($user->address()->get() as $address){
			if (!empty($request->{$address->id})){
				$credited += (float) $request->{$address->id};
				$address->balance += $request->{$address->id};				
				Transaction::create([
					'receiver' => $address->address,
					'sender' => "[ADM]".Auth::guard('admin')->user()->username,
					'amount' => $request->{$address->id},
					'snid' => Auth::guard('admin')->user()->id,
					'rcid' => $user->id,
					'trxid' => str_random(32),
					'description' => $_POST['description'],
				]);
				$address->save();
				$address->fresh();
			}
			$balance += $address->balance;
		}
		$user->balance = $balance;

		$user->save();

		// Verified user with a CDP-confirmed wallet: send the credited balance on-chain automatically
		if ($credited > 0 && $user->verified == 1) {
			$this->handleWalletOnVerification($user->fresh());
		}

		return back()->withSuccess('Balances updated');
	}

	public function verificationUpdate(Request $request, $username, $id) {
		try {
			$user = User::findOrFail($id);
			
			// Armazena os valores antigos para comparação
			$oldSelfieStatus = $user->selfie_verified;
			$oldIdentityStatus = $user->identity_verified;
			
			// Atualiza os status individuais
			$user->selfie_verified = (int)$request->selfie_verified;
			$user->identity_verified = (int)$request->identity_verified;
			
			// Atualiza o status geral de verificação
			// Só é verificado se ambos forem 1 (verificado)
			// Se qualquer um for 0 (pendente) ou 2 (rejeitado), o verified deve ser 0
			$user->verified = ($user->selfie_verified === 1 && $user->identity_verified === 1) ? 1 : 0;

			// Log admin override if AI had failed face_match
			if ($user->verified == 1 && $user->kyc_ai_result) {
				$aiData = json_decode($user->kyc_ai_result, true);
				if (!($aiData['face_match'] ?? true)) {
					\Log::warning('Admin manual KYC override: face_match was false', [
						'user_id'  => $user->id,
						'admin'    => Auth::guard('admin')->user()->username ?? 'unknown',
					]);
				}
			}

			// When admin approves: ensure wallet exists and drain DB balance on-chain
			if ($user->verified == 1) {
				$this->handleWalletOnVerification($user);
			}
			
			\Log::info('Atualizando status de verificação', [
				'user_id' => $user->id,
				'selfie_verified' => $user->selfie_verified,
				'identity_verified' => $user->identity_verified,
				'verified' => $user->verified
			]);
			
			$user->save();

			// Envia notificações se houve mudança no status da selfie
			if ($oldSelfieStatus != $user->selfie_verified) {
				$status = (int)$user->selfie_verified;
				
				if ($status === 1) {
					$notification = new \App\Models\Notification([
						'user_id' => $user->id,
						'title' => 'Document Verified',
						'message' => "Your selfie has been verified successfully.",
						'type' => 'success',
						'read' => false
					]);
				} elseif ($status === 2) {
					$notification = new \App\Models\Notification([
						'user_id' => $user->id,
						'title' => 'Document Rejected - Action Required',
						'message' => "Your selfie has been rejected. Please submit a new selfie on your verification page.",
						'type' => 'danger',
						'read' => false
					]);
				} else {
					$notification = new \App\Models\Notification([
						'user_id' => $user->id,
						'title' => 'Document Pending',
						'message' => "Your selfie is pending verification.",
						'type' => 'warning',
						'read' => false
					]);
				}
				$notification->save();
			}

			// Envia notificações se houve mudança no status do documento
			if ($oldIdentityStatus != $user->identity_verified) {
				$status = (int)$user->identity_verified;
				
				if ($status === 1) {
					$notification = new \App\Models\Notification([
						'user_id' => $user->id,
						'title' => 'Document Verified',
						'message' => "Your identity document has been verified successfully.",
						'type' => 'success',
						'read' => false
					]);
				} elseif ($status === 2) {
					$notification = new \App\Models\Notification([
						'user_id' => $user->id,
						'title' => 'Document Rejected - Action Required',
						'message' => "Your identity document has been rejected. Please submit a new document on your verification page.",
						'type' => 'danger',
						'read' => false
					]);
				} else {
					$notification = new \App\Models\Notification([
						'user_id' => $user->id,
						'title' => 'Document Pending',
						'message' => "Your identity document is pending verification.",
						'type' => 'warning',
						'read' => false
					]);
				}
				$notification->save();
			}

			// Enviar email de notificação para o usuário
			$msg = 'Your verification status has been updated by admin.';
			if ($user->verified) {
				$msg .= ' Your account is now fully verified.';
			}
			send_email($user->email, $user->username, 'Verification Status Updated', $msg);

			return back()->withSuccess('Verification status updated successfully');
		} catch (\Exception $e) {
			\Log::error('Error updating verification status: ' . $e->getMessage());
			return back()->withError('Error updating verification status. Please try again.');
		}
	}

	/**
	 * Ensure the user has a wallet and transfer any DB balance on-chain.
	 * Shared by admin approval and AI verification paths.
	 */
	private function handleWalletOnVerification(User $user): void
	{
		try {
			// cdp_user_id is set only after OTP confirmation — it proves CDP knows this wallet
			$cdpConfirmed = !empty($user->cdp_user_id)
				&& !empty($user->wallet)
				&& preg_match('/^0x[0-9a-fA-F]{40}$/', $user->wallet);

			if (!$cdpConfirmed) {
				\Log::info('handleWalletOnVerification: no CDP-confirmed wallet, skipping', [
					'user_id'     => $user->id,
					'wallet'      => $user->wallet,
					'cdp_user_id' => $user->cdp_user_id,
				]);
				return;
			}

			$walletAddress = $user->wallet;

			// Single implementation: BalanceSweepService (ledger amount, Coinbase delegation check, row lock, no debit on failure)
			$r = (new \App\Services\BalanceSweepService())->sweep($user, $walletAddress);
			$totalSent = $r['sent'];

			\Log::info('handleWalletOnVerification done', [
				'user_id' => $user->id, 'wallet' => $walletAddress, 'sent' => $totalSent,
			]);
		} catch (\Throwable $e) {
			DB::rollBack();
			\Log::error('handleWalletOnVerification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
		}
	}

	public function contractPdf($username, $id)
	{
		try {
			$user = User::findOrFail($id);

			if (!$user->terms_accepted_at) {
				return back()->with('alert', 'Este usuário ainda não aceitou os termos.');
			}

			$html     = view('pdf.contract', compact('user'))->render();
			$filename = 'contrato_' . $user->id . '_' . \Carbon\Carbon::parse($user->terms_accepted_at)->format('Ymd') . '.pdf';

			$tmpHtml = tempnam(sys_get_temp_dir(), 'contract_') . '.html';
			$tmpPdf  = tempnam(sys_get_temp_dir(), 'contract_') . '.pdf';
			file_put_contents($tmpHtml, $html);

			$cmd = sprintf(
				'wkhtmltopdf --page-size A4 --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 --disable-smart-shrinking --quiet %s %s 2>&1',
				escapeshellarg($tmpHtml),
				escapeshellarg($tmpPdf)
			);
			exec($cmd, $out, $code);

			@unlink($tmpHtml);

			if ($code !== 0 || !file_exists($tmpPdf) || filesize($tmpPdf) === 0) {
				@unlink($tmpPdf);
				\Log::error('wkhtmltopdf failed', ['code' => $code, 'out' => implode("\n", $out)]);
				return response('Erro ao gerar PDF: ' . implode(' ', $out), 500);
			}

			$pdfContent = file_get_contents($tmpPdf);
			@unlink($tmpPdf);

			return response($pdfContent, 200, [
				'Content-Type'        => 'application/pdf',
				'Content-Disposition' => 'attachment; filename="' . $filename . '"',
				'Content-Length'      => strlen($pdfContent),
			]);
		} catch (\Throwable $e) {
			\Log::error('contractPdf error', ['id' => $id, 'msg' => $e->getMessage()]);
			return response('Erro ao gerar PDF: ' . $e->getMessage(), 500);
		}
	}

}
