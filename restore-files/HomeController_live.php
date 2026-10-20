<?php

namespace App\Http\Controllers;

use App\Address;
use App\Gateway;
use App\General;
use App\Ico;
use App\Lib\GoogleAuthenticator;
use App\Sell;
use App\User;
use Auth;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input as input;
use Session;

class HomeController extends Controller {

	public function __construct() {
		$this->middleware(['auth', 'ckstatus']);
	}

	public function index() {
		$nexts = Ico::where('status', '!=', 2)->where('status', '!=', 3)->get();
		// Verified user with wallet + active delegation but balance still on the platform: send it on-chain automatically
		$me = Auth::user();
		if ($me && $me->verified == 1 && $me->wallet && $me->cdp_user_id) {
			try {
				(new \App\Services\BalanceSweepService())->sweep($me);
			} catch (\Throwable $e) {
				\Log::error('auto sweep (home) failed', ['user_id' => $me->id, 'error' => $e->getMessage()]);
			}
		}
		$adds = Address::where('user_id', Auth::id())->orderBy('id', 'DESC')->take(10)->get();
		return view('user.home', compact('adds', 'nexts'));
	}

	public function myCoin() {
		$coins = Sell::where('user_id', Auth::id())->where('status', 1)->get();
		return view('user.mycoin', compact('coins'));
	}

	public function buyIco() {
		$gates = Gateway::where('status', 1)->get();
		$ico = Ico::where('status', 1)->first();
		return view('user.buy', compact('gates', 'ico'));
	}

	public function buyPreview(Request $request) {
		$this->validate($request,
			[
				'amount' => 'required',
				'gateway' => 'required',
			]);
		$ico = Ico::where('status', 1)->first();
		$total = $request->amount + $ico->sold;
		if ($request->amount <= 0 || $total > $ico->quant) {
			return back()->with('alert', 'Invalid Amount');
		} else {
			$gate = Gateway::findOrFail($request->gateway);
			if (is_null($gate)) {
				return back()->with('alert', 'Please Select a Payment Gateway');
			} else {
				$ico = Ico::where('status', 1)->first();

				if ($gate->id == 3 || $gate->id == 6 || $gate->id == 7 || $gate->id == 8) {
					$all = file_get_contents("https://blockchain.info/ticker");
					$res = json_decode($all);
					$btcrate = $res->USD->last;

					$amount = intval($request->amount);
					$usd = $ico->price * $amount;
					$btcamount = $usd / $btcrate;
					$btc = round($btcamount, 8);

					$sell['user_id'] = Auth::id();
					$sell['ico_id'] = $ico->id;
					$sell['gateway_id'] = $gate->id;
					$sell['amount'] = $amount;
					$sell['bcam'] = $btc;
					$sell['status'] = 0;
					$sell['trx'] = str_random(16);
					Sell::create($sell);
					Session::put('Track', $sell['trx']);

					return view('user.preview', compact('btc', 'gate', 'ico', 'amount'));
				} else {
					$amount = intval($request->amount);
					$usd = $ico->price * $amount;

					$sell['user_id'] = Auth::id();
					$sell['ico_id'] = $ico->id;
					$sell['gateway_id'] = $gate->id;
					$sell['amount'] = $amount;
					$sell['status'] = 0;
					$sell['trx'] = str_random(16);
					Sell::create($sell);
					Session::put('Track', $sell['trx']);

					return view('user.preview', compact('usd', 'gate', 'ico', 'amount'));
				}
			}
		}
	}

	public function referal() {
		$refers = User::where('refer', Auth::id())->paginate(10);
		return view('user.referal', compact('refers'));
	}

	//Change password
	public function changepass() {
		$user = User::find(Auth::id());
		return view('auth.passwords.change', compact('user'));
	}

	public function chnpass() {
		$user = User::find(Auth::id());

		if (Hash::check(Input::get('passwordold'), $user['password']) && Input::get('password') == Input::get('password_confirmation')) {
			$user->password = bcrypt(Input::get('password'));
			$user->save();

			$msg = 'Password Changed Successfully';
			send_email($user->email, $user->username, 'Password Changed', $msg);
			$sms = 'Password Changed Successfully';
			send_sms($user->mobile, $sms);

			return back()->with('success', 'Password Changed');
		} else {
			return back()->with('alert', 'Password Not Changed');
		}
	}

	public function google2fa() {
		try {
			$gnl = General::first();
			$ga = new GoogleAuthenticator();
			$secret = $ga->createSecret();
			
			// Gera a URL do otpauth de forma mais simples
			$otpauthUrl = sprintf(
				'otpauth://totp/%s:%s?secret=%s&issuer=%s',
				'GOLDOFIR',
				'Gold',
				$secret,
				'GOLDOFIR'
			);
			
			// Gera a URL do QR Code usando uma API pública alternativa
			$qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
				'size' => '200x200',
				'data' => $otpauthUrl
			]);

			// Se o usuário já tem um código, gera o QR code anterior
			$prevcode = Auth::user()->secretcode;
			$prevqr = null;
			
			if ($prevcode && $prevcode !== '0') {
				$prevOtpauthUrl = sprintf(
					'otpauth://totp/%s:%s?secret=%s&issuer=%s',
					'GOLDOFIR',
					'Gold',
					$prevcode,
					'GOLDOFIR'
				);
				
				$prevqr = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
					'size' => '200x200',
					'data' => $prevOtpauthUrl
				]);
			}

			// Log para debug
			\Log::info('QR Code URL: ' . $qrCodeUrl);
			\Log::info('TOTP URL: ' . $otpauthUrl);

			return view('user.goauth.create', compact('secret', 'qrCodeUrl', 'prevcode', 'prevqr'));
			
		} catch (\Exception $e) {
			\Log::error('Erro ao gerar QR Code: ' . $e->getMessage());
			return back()->with('alert', 'Erro ao gerar QR Code. Por favor, tente novamente.');
		}
	}

	public function create2fa(Request $request)
	{
		try {
			$this->validate($request, [
				'key' => 'required|string|min:16',
				'code' => 'required|string|size:6'
			]);

			$user = User::find(Auth::id());
			$ga = new GoogleAuthenticator();

			$secret = $request->key;
			$currentTime = floor(time() / 30);
			
			// Verifica o código atual e os códigos adjacentes (30 segundos antes e depois)
			for ($t = -1; $t <= 1; $t++) {
				$calculatedCode = $ga->getCode($secret, $currentTime + $t);
				if ($calculatedCode === $request->code) {
					$user->secretcode = $secret;
					$user->tauth = 1;
					$user->tfver = 1;
					$user->save();

					// Envia notificações
					$msg = 'Autenticação de dois fatores ativada com sucesso';
					send_email($user->email, $user->username, 'Google 2FA', $msg);
					send_sms($user->mobile, $msg);

					return back()->with('success', 'Autenticação de dois fatores ativada com sucesso');
				}
			}

			// Se chegou aqui, o código está incorreto
			return back()->with('alert', 'Código de verificação inválido. Por favor, tente novamente.')
						->withInput($request->except('code'));

		} catch (\Exception $e) {
			\Log::error('Erro ao ativar 2FA: ' . $e->getMessage());
			return back()->with('alert', 'Erro ao ativar autenticação de dois fatores. Por favor, tente novamente.');
		}
	}

	public function disable2fa(Request $request)
	{
		try {
			$this->validate($request, [
				'code' => 'required|string|size:6'
			]);

			$user = User::find(Auth::id());
			$ga = new GoogleAuthenticator();

			$secret = $user->secretcode;
			$currentTime = floor(time() / 30);
			
			// Verifica o código atual e os códigos adjacentes
			for ($t = -1; $t <= 1; $t++) {
				$calculatedCode = $ga->getCode($secret, $currentTime + $t);
				if ($calculatedCode === $request->code) {
					$user->tauth = 0;
					$user->tfver = 1;
					$user->secretcode = '0';
					$user->save();

					// Envia notificações
					$msg = 'Autenticação de dois fatores desativada com sucesso';
					send_email($user->email, $user->username, 'Google 2FA', $msg);
					send_sms($user->mobile, $msg);

					return back()->with('success', 'Autenticação de dois fatores desativada com sucesso');
				}
			}

			return back()->with('alert', 'Código de verificação inválido. Por favor, tente novamente.');

		} catch (\Exception $e) {
			\Log::error('Erro ao desativar 2FA: ' . $e->getMessage());
			return back()->with('alert', 'Erro ao desativar autenticação de dois fatores. Por favor, tente novamente.');
		}
	}

	public function check2fa()
	{
		return response()->json([
			'enabled' => auth()->user()->tauth
		]);
	}
}
