<?php

namespace App\Services;

use App\General;
use Illuminate\Support\Facades\Log;
use kornrunner\Ethereum\Transaction;
use kornrunner\Keccak;

/**
 * Sends FGOL tokens from the vault wallet to a user's EVM address via ERC-20 transfer.
 * Uses pure-PHP secp256k1 (GMP) + kornrunner for signing — no Node.js needed.
 */
class FGOLTransferService
{
    private const NETWORKS = [
        'base-mainnet'    => ['rpc' => 'https://mainnet.base.org',  'chainId' => 8453],
        'polygon-mainnet' => ['rpc' => 'https://polygon-bor-rpc.publicnode.com', 'chainId' => 137],
        'base-sepolia'    => ['rpc' => 'https://sepolia.base.org',  'chainId' => 84532],
    ];

    // transfer(address,uint256) → selector = keccak256('transfer(address,uint256)')[0:4]
    private const ERC20_TRANSFER_SELECTOR = 'a9059cbb';

    // ─── Public ──────────────────────────────────────────────────────────────────

    /**
     * Transfer FGOL tokens from the vault to a user's wallet.
     *
     * Returns ['success'=>bool, 'txHash'=>string|null, 'queued'=>bool, 'error'=>string|null]
     */
    public function transfer(string $toAddress, float $amount): array
    {
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $toAddress)) {
            Log::error('FGOLTransferService: invalid EVM address rejected', ['to' => $toAddress]);
            return ['success' => false, 'txHash' => null, 'error' => 'Endereço EVM inválido: ' . $toAddress];
        }

        try {
            $general = General::first();

            $contractAddress = $general->fgol_contract_address ?? '';
            $vaultPrivateKey = $general->vault_wallet_private_key ?? '';
            $networkKey      = $general->fgol_network ?? 'base-mainnet';

            // Token not deployed yet — queue and skip on-chain step
            if (empty($contractAddress)) {
                Log::info('FGOL transfer queued — contract not deployed yet', [
                    'to' => $toAddress, 'amount' => $amount,
                ]);
                return [
                    'success' => true,
                    'txHash'  => null,
                    'queued'  => true,
                    'message' => 'Token pendente de deploy — transferência será executada quando o contrato for configurado.',
                ];
            }

            if (empty($vaultPrivateKey)) {
                throw new \RuntimeException('Chave privada da carteira cofre não configurada.');
            }

            $network    = self::NETWORKS[$networkKey] ?? self::NETWORKS['base-mainnet'];
            $privateKey = ltrim(trim($vaultPrivateKey), '0x');

            // Derive vault address from private key
            $vaultAddress = $this->privateKeyToAddress($privateKey);

            // Build ERC-20 calldata
            $calldata = $this->buildTransferData($toAddress, $amount);

            // Fetch nonce, gas price, gas limit from RPC
            $nonce    = $this->getNonce($vaultAddress, $network['rpc']);
            $gasPrice = $this->getGasPrice($network['rpc']);
            $gasLimit = $this->estimateGas($vaultAddress, $contractAddress, $calldata, $network['rpc']);

            // Build legacy transaction (EIP-155)
            $tx = new Transaction(
                '0x' . ($nonce === 0 ? '0' : dechex($nonce)),
                '0x' . dechex((int)bcmul((string)$gasPrice, '1.1', 0)),
                '0x' . dechex($gasLimit),
                $contractAddress,
                '0x0',
                '0x' . $calldata
            );

            $rawHex = $tx->getRaw($privateKey, $network['chainId']);
            $txHash = $this->sendRawTransaction('0x' . $rawHex, $network['rpc']);

            Log::info('FGOL transfer broadcasted', [
                'to'      => $toAddress,
                'amount'  => $amount,
                'txHash'  => $txHash,
                'network' => $networkKey,
            ]);

            return ['success' => true, 'txHash' => $txHash, 'queued' => false];

        } catch (\Throwable $e) {
            Log::error('FGOLTransferService::transfer failed', [
                'to'    => $toAddress,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'txHash' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * On-chain FGOL balances for many wallets in ONE batched JSON-RPC call (cached 60s).
     * Returns [lowercased address => float|null]; null = could not be read.
     */
    public static function onchainBalances(array $addresses): array
    {
        $addresses = array_values(array_unique(array_filter(array_map('strtolower', $addresses), fn($a) => preg_match('/^0x[0-9a-f]{40}$/', $a))));
        if (!$addresses) return [];

        $general  = General::first();
        $contract = $general->fgol_contract_address ?? '';
        if (empty($contract)) return array_fill_keys($addresses, null);

        $net  = self::NETWORKS[$general->fgol_network ?? 'base-mainnet'] ?? self::NETWORKS['base-mainnet'];
        $urls = [$net['rpc']];
        if (($general->fgol_network ?? '') === 'polygon-mainnet') {
            $urls = array_merge($urls, ['https://polygon.drpc.org', 'https://1rpc.io/matic']);
        }

        $fetch = function () use ($addresses, $contract, $urls) {
            $batch = [];
            foreach ($addresses as $i => $addr) {
                $batch[] = [
                    'jsonrpc' => '2.0', 'id' => $i + 1, 'method' => 'eth_call',
                    'params'  => [['to' => $contract, 'data' => '0x70a08231' . str_pad(substr($addr, 2), 64, '0', STR_PAD_LEFT)], 'latest'],
                ];
            }
            foreach ($urls as $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS     => json_encode($batch),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT        => 8,
                ]);
                $res = curl_exec($ch);
                curl_close($ch);
                $data = json_decode((string) $res, true);
                if (!is_array($data) || isset($data['error'])) continue;

                $out = array_fill_keys($addresses, null);
                foreach ($data as $row) {
                    $idx = ($row['id'] ?? 0) - 1;
                    $hex = $row['result'] ?? null;
                    if (isset($addresses[$idx]) && is_string($hex) && preg_match('/^0x[0-9a-fA-F]+$/', $hex)) {
                        $out[$addresses[$idx]] = (float) bcdiv(gmp_strval(gmp_init(substr($hex, 2), 16)), '1000000000000000000', 4);
                    }
                }
                return $out;
            }
            return array_fill_keys($addresses, null);
        };

        // Cache is an optimisation only — never let a cache write problem hide the balances
        try {
            return \Illuminate\Support\Facades\Cache::remember('fgol_onchain_' . md5($contract . implode(',', $addresses)), 60, $fetch);
        } catch (\Throwable $e) {
            return $fetch();
        }
    }

    // ─── ERC-20 helpers ──────────────────────────────────────────────────────────

    private function buildTransferData(string $to, float $amount): string
    {
        // Amount in wei with 18 decimals
        $amountWei = bcmul(
            number_format($amount, 0, '.', ''),
            bcpow('10', '18')
        );

        $addrPadded   = str_pad(ltrim(strtolower($to), '0x'), 64, '0', STR_PAD_LEFT);
        $amountPadded = $this->decToUint256Hex($amountWei);

        return self::ERC20_TRANSFER_SELECTOR . $addrPadded . $amountPadded;
    }

    private function decToUint256Hex(string $dec): string
    {
        $hex = '';
        $n   = $dec;
        while (bccomp($n, '0') > 0) {
            $hex = dechex((int)bcmod($n, '16')) . $hex;
            $n   = bcdiv($n, '16', 0);
        }
        return str_pad($hex ?: '0', 64, '0', STR_PAD_LEFT);
    }

    // ─── RPC calls ───────────────────────────────────────────────────────────────

    private function getNonce(string $address, string $rpc): int
    {
        if (empty($address)) return 0;
        $res = $this->rpcCall($rpc, 'eth_getTransactionCount', [$address, 'pending']);
        return (int)hexdec(ltrim($res['result'] ?? '0x0', '0x'));
    }

    private function getGasPrice(string $rpc): int
    {
        $res = $this->rpcCall($rpc, 'eth_gasPrice', []);
        return isset($res['result']) ? (int)hexdec(ltrim($res['result'], '0x')) : 1_000_000_000;
    }

    private function estimateGas(string $from, string $to, string $data, string $rpc): int
    {
        $res = $this->rpcCall($rpc, 'eth_estimateGas', [[
            'from' => $from ?: '0x0000000000000000000000000000000000000001',
            'to'   => $to,
            'data' => '0x' . $data,
        ]]);
        if (isset($res['result'])) {
            return max((int)((int)hexdec(ltrim($res['result'], '0x')) * 1.2), 60_000);
        }
        return 80_000; // safe fallback for ERC-20 transfer
    }

    private function sendRawTransaction(string $rawHex, string $rpc): string
    {
        $res = $this->rpcCall($rpc, 'eth_sendRawTransaction', [$rawHex]);
        if (isset($res['error'])) {
            throw new \RuntimeException('RPC error: ' . json_encode($res['error']));
        }
        return $res['result'] ?? '';
    }

    private function rpcCall(string $rpc, string $method, array $params): array
    {
        $ch = curl_init($rpc);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return $raw ? (json_decode($raw, true) ?? []) : [];
    }

    // ─── secp256k1 address derivation (pure GMP) ─────────────────────────────────

    /**
     * Derive Ethereum address from a hex private key using pure GMP math.
     * Verified against known Hardhat test vectors.
     */
    private function privateKeyToAddress(string $hexKey): string
    {
        $p  = gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16);
        $Gx = gmp_init('79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798', 16);
        $Gy = gmp_init('483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8', 16);

        $k   = gmp_init($hexKey, 16);
        $pub = $this->ecMul($k, [$Gx, $Gy], $p);

        $pubHex = str_pad(gmp_strval($pub[0], 16), 64, '0', STR_PAD_LEFT)
                . str_pad(gmp_strval($pub[1], 16), 64, '0', STR_PAD_LEFT);

        $hash = Keccak::hash(hex2bin($pubHex), 256);
        return '0x' . substr($hash, -40);
    }

    private function ecAdd(?array $P, ?array $Q, $p): ?array
    {
        if ($P === null) return $Q;
        if ($Q === null) return $P;
        [$Px, $Py] = $P;
        [$Qx, $Qy] = $Q;
        if (gmp_cmp($Px, $Qx) === 0 && gmp_cmp($Py, gmp_mod(gmp_neg($Qy), $p)) === 0) return null;
        if (gmp_cmp($Px, $Qx) === 0) {
            $m = gmp_mod(gmp_mul(gmp_mul(gmp_init(3), gmp_powm($Px, gmp_init(2), $p)), gmp_invert(gmp_mul(gmp_init(2), $Py), $p)), $p);
        } else {
            $m = gmp_mod(gmp_mul(gmp_sub($Qy, $Py), gmp_invert(gmp_sub($Qx, $Px), $p)), $p);
        }
        $x = gmp_mod(gmp_sub(gmp_sub(gmp_powm($m, gmp_init(2), $p), $Px), $Qx), $p);
        $y = gmp_mod(gmp_sub(gmp_mul($m, gmp_sub($Px, $x)), $Py), $p);
        return [$x, $y];
    }

    private function ecMul($k, array $G, $p): array
    {
        $R    = null;
        $Q    = $G;
        $bits = gmp_strval($k, 2);
        for ($i = strlen($bits) - 1; $i >= 0; $i--) {
            if ($bits[$i] === '1') $R = $this->ecAdd($R, $Q, $p);
            $Q = $this->ecAdd($Q, $Q, $p);
        }
        return $R;
    }
}
