<?php

namespace App\Services;

use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves a user's platform (database) balance on-chain to their own wallet.
 * Amount = sum of addresses.balance (the real ledger). users.balance is NOT trusted: it is a stale aggregate.
 * Only runs when Coinbase confirms an active delegation, and never debits the database unless the on-chain send worked.
 */
class BalanceSweepService
{
    /**
     * @return array ['sent' => float, 'tx' => string|null, 'reason' => string]
     */
    public function sweep(User $user, ?string $walletAddress = null): array
    {
        $wallet = $walletAddress ?: (string) $user->wallet;
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $wallet)) {
            return ['sent' => 0.0, 'tx' => null, 'reason' => 'invalid_wallet'];
        }

        // Cheap check first: do not call Coinbase on every page view when there is nothing to send
        if ($this->pendingAmount($user->id) <= 0) {
            return ['sent' => 0.0, 'tx' => null, 'reason' => 'nothing_to_send'];
        }

        $deleg = (new CDPService())->hasActiveDelegation((string) $user->cdp_user_id, $wallet);
        if (!$deleg['active']) {
            Log::warning('Sweep: delegação CDP não confirmada — saldo mantido na plataforma', [
                'user_id' => $user->id, 'wallet' => $wallet, 'reason' => $deleg['reason'],
            ]);
            return ['sent' => 0.0, 'tx' => null, 'reason' => 'no_delegation'];
        }

        return DB::transaction(function () use ($user, $wallet) {
            // Row lock: a second simultaneous request waits here, then finds nothing left to send
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            $rows  = DB::table('addresses')->where('user_id', $user->id)->where('balance', '>', 0)->get();
            $total = 0.0;
            foreach ($rows as $row) {
                $total += (float) $row->balance;
            }
            if ($total <= 0) {
                return ['sent' => 0.0, 'tx' => null, 'reason' => 'nothing_to_send'];
            }

            $legacy = (float) DB::table('users')->where('id', $user->id)->value('balance');
            if (abs($legacy - $total) > 0.01) {
                Log::warning('Sweep: users.balance diverge da soma de addresses — usando addresses', [
                    'user_id' => $user->id, 'users_balance' => $legacy, 'addresses_sum' => $total,
                ]);
            }

            $logId = DB::table('transaction_logs')->insertGetId([
                'user_id'        => $user->id,
                'wallet_address' => $wallet,
                'amount'         => $total,
                'status'         => 'pending',
                'response'       => 'wallet_creation_transfer:pending',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            $result = (new FGOLTransferService())->transfer($wallet, $total);
            $txHash = $result['txHash'] ?? null;
            $ok     = $result['success'] || ($result['queued'] ?? false);

            DB::table('transaction_logs')->where('id', $logId)->update([
                'status'     => $ok ? 'success' : 'failed',
                'response'   => $txHash
                    ? json_encode(['transactionHash' => $txHash])
                    : json_encode(['error' => $result['error'] ?? (($result['queued'] ?? false) ? 'queued — aguardando deploy do contrato' : 'sem txHash')]),
                'updated_at' => now(),
            ]);

            if (!$ok) {
                Log::error('Sweep: envio on-chain falhou — saldo mantido na plataforma', [
                    'user_id' => $user->id, 'wallet' => $wallet, 'amount' => $total, 'error' => $result['error'] ?? null,
                ]);
                return ['sent' => 0.0, 'tx' => null, 'reason' => 'transfer_failed'];
            }

            foreach ($rows as $row) {
                $amt = (float) $row->balance;
                if ($amt <= 0) continue;
                DB::table('addresses')->where('id', $row->id)->update(['balance' => 0]);
                DB::table('transactions')->insert([
                    'receiver'    => $wallet,
                    'rcid'        => $user->id,
                    'sender'      => $row->address,
                    'snid'        => $user->id,
                    'amount'      => $amt,
                    'trxid'       => 'onchain_sweep_' . $row->id . '_' . time(),
                    'description' => 'Saldo transferido para carteira on-chain',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
            DB::table('users')->where('id', $user->id)->update(['balance' => 0]);

            Log::info('Sweep: saldo enviado on-chain', ['user_id' => $user->id, 'wallet' => $wallet, 'amount' => $total, 'tx' => $txHash]);
            return ['sent' => $total, 'tx' => $txHash, 'reason' => 'ok'];
        });
    }

    private function pendingAmount(int $userId): float
    {
        return (float) DB::table('addresses')->where('user_id', $userId)->where('balance', '>', 0)->sum('balance');
    }
}
