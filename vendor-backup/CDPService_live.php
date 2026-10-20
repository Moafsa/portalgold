<?php

namespace App\Services;

use App\General;
use Illuminate\Support\Facades\Log;

/**
 * Coinbase Developer Platform (CDP) service.
 *
 * API Key authentication uses EdDSA (Ed25519) with a 64-byte key stored as base64.
 * The newer CDP portal generates Ed25519 keys — NOT the old PEM EC format.
 *
 * Key formats (from @coinbase/cdp-sdk v1.55.0 jwt.js):
 *  - apiKeySecret : base64-encoded 64 bytes (bytes 0–31 = seed, 32–63 = public key)
 *  - walletSecret : base64-encoded DER-encoded ES256 private key (used for wallet JWTs)
 */
class CDPService
{
    private const API_HOST     = 'api.cdp.coinbase.com';
    private const API_BASE     = 'https://api.cdp.coinbase.com';
    private const POLYGON_RPC  = 'https://polygon-bor-rpc.publicnode.com';
    // Fallbacks used when the primary public RPC errors intermittently
    private const POLYGON_RPC_FALLBACKS = ['https://polygon.drpc.org', 'https://1rpc.io/matic'];
    private const POLYGON_CHAIN = 137;

    // Gas top-up bounds (wei as decimal strings)
    private const MIN_TOPUP_WEI = '3000000000000000';   // 0.003 MATIC
    private const MAX_TOPUP_WEI = '100000000000000000'; // 0.100 MATIC

    // ─── Credentials ────────────────────────────────────────────────────────────

    private function creds(): array
    {
        $g = General::first();
        return [
            'keyId'        => $g->cdp_api_key_id     ?? '',
            'keySecret'    => $g->cdp_api_key_secret  ?? '',
            'walletSecret' => $g->cdp_wallet_secret   ?? '',
        ];
    }

    // ─── JWT generation ─────────────────────────────────────────────────────────

    /**
     * Generate a JWT for CDP REST API calls using Ed25519 (EdDSA).
     * The base64 secret encodes 64 bytes: [0..31] = private seed, [32..63] = public key.
     */
    private function buildApiJwt(string $method, string $path): string
    {
        $creds = $this->creds();
        $keyId  = $creds['keyId'];
        $secret = $creds['keySecret'];

        if (empty($keyId) || empty($secret)) {
            throw new \RuntimeException('CDP API Key ID ou Secret não configurados.');
        }

        $keyBytes = base64_decode($secret);
        if (strlen($keyBytes) !== 64) {
            throw new \RuntimeException(
                'CDP API Key Secret inválido: esperado 64 bytes (Ed25519), recebido ' . strlen($keyBytes) . ' bytes.'
            );
        }

        $now    = time();
        $nonce  = bin2hex(random_bytes(16));

        $header = $this->b64url(json_encode([
            'alg'   => 'EdDSA',
            'kid'   => $keyId,
            'typ'   => 'JWT',
            'nonce' => $nonce,
        ]));

        $payload = $this->b64url(json_encode([
            'sub'  => $keyId,
            'iss'  => 'cdp',
            'aud'  => ['cdp_service'],
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + 120,
            'jti'  => bin2hex(random_bytes(8)),
            'uris' => ["{$method} " . self::API_HOST . $path],
        ]));

        $msg       = $header . '.' . $payload;
        $signature = sodium_crypto_sign_detached($msg, $keyBytes);

        return $msg . '.' . $this->b64url($signature);
    }

    /**
     * Generate a wallet-specific JWT (ES256) for endpoints that need wallet auth.
     * walletSecret = base64-encoded DER private key (P-256/ES256).
     */
    private function buildWalletJwt(string $method, string $path, ?array $body = null): string
    {
        $creds = $this->creds();
        if (empty($creds['walletSecret'])) {
            throw new \RuntimeException('CDP Wallet Secret não configurado.');
        }

        $raw = trim($creds['walletSecret']);

        // Accept multiple formats from the CDP portal:
        // 1. PEM directly (-----BEGIN EC PRIVATE KEY----- or -----BEGIN PRIVATE KEY-----)
        // 2. Base64-encoded DER bytes (legacy format)
        if (str_contains($raw, '-----BEGIN')) {
            // Already PEM — use directly
            $pemKey = $raw;
        } else {
            $decoded = base64_decode($raw, true);
            if ($decoded === false || strlen($decoded) < 32) {
                throw new \RuntimeException(
                    'CDP Wallet Secret inválido. Cole a chave PEM completa (-----BEGIN EC PRIVATE KEY-----...) no campo.'
                );
            }
            // Wrap raw DER bytes in PKCS8 PEM envelope
            $pemKey = "-----BEGIN PRIVATE KEY-----\n"
                    . chunk_split(base64_encode($decoded), 64, "\n")
                    . "-----END PRIVATE KEY-----";
        }

        $ecKey = openssl_pkey_get_private($pemKey);
        if (!$ecKey) {
            throw new \RuntimeException(
                'CDP Wallet Secret: formato de chave inválido. Use o bloco PEM completo do portal CDP.'
            );
        }

        $now   = time();
        $nonce = bin2hex(random_bytes(8));
        $jti   = bin2hex(random_bytes(8));

        $claims = [
            'uris' => ["{$method} " . self::API_HOST . $path],
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + 120,
            'jti'  => $jti,
        ];

        if (!empty($body)) {
            $claims['reqHash'] = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $header  = $this->b64url(json_encode(['alg' => 'ES256', 'typ' => 'JWT', 'nonce' => $nonce]));
        $payload = $this->b64url(json_encode($claims));
        $msg     = $header . '.' . $payload;

        openssl_sign($msg, $derSig, $ecKey, OPENSSL_ALGO_SHA256);

        // JWT ES256 requires raw R||S (64 bytes), not DER-encoded
        return $msg . '.' . $this->b64url($this->derSigToJose($derSig));
    }

    /**
     * Convert DER-encoded ECDSA signature to raw R||S format required by JWT ES256.
     * DER: 0x30 [len] 0x02 [r_len] [r] 0x02 [s_len] [s]
     * JOSE: [r padded to 32 bytes] || [s padded to 32 bytes]
     */
    private function derSigToJose(string $der): string
    {
        $offset = 2; // skip SEQUENCE tag + 1-byte length
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;
        $sLen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLen);
        // Strip leading 0x00 padding byte (DER uses it to keep sign positive) then pad to 32
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    // ─── HTTP helper ─────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $body = [], bool $walletAuth = false): array
    {
        $apiJwt = $this->buildApiJwt($method, $path);

        $ch = curl_init(self::API_BASE . $path);
        $headers = [
            'Authorization: Bearer ' . $apiJwt,
            'Content-Type: application/json',
        ];
        if ($walletAuth) {
            // Wallet endpoints require BOTH Authorization (API key) AND X-Wallet-Auth (wallet key)
            $walletJwt = $this->buildWalletJwt($method, $path, $body ?: null);
            $headers[] = 'X-Wallet-Auth: ' . $walletJwt;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('CDP HTTP error: ' . $err);
        }

        $data = json_decode($response, true) ?? [];
        $data['__http_status'] = $code;
        return $data;
    }

    // ─── Public API methods ──────────────────────────────────────────────────────

    /**
     * Test both credentials by hitting the accounts list endpoint.
     */
    public function testCredentials(): array
    {
        try {
            // Test API key JWT
            $result = $this->request('GET', '/platform/v2/evm/accounts');
            $apiOk  = ($result['__http_status'] ?? 0) === 200 || ($result['__http_status'] ?? 0) === 404;

            return [
                'api_key_ok'     => $apiOk,
                'http_status'    => $result['__http_status'] ?? 0,
                'response_keys'  => array_keys($result),
            ];
        } catch (\Throwable $e) {
            return ['api_key_ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send EVM transaction on behalf of a delegated user.
     * Used for transferring FGOL tokens after wallet creation.
     *
     * @param  string $cdpUserId   The end-user's CDP user ID
     * @param  string $address     The user's EVM address
     * @param  string $network     e.g. 'base-mainnet' or 'polygon-mainnet'
     * @param  string $txHex       Unsigned serialized transaction hex
     */
    public function sendUserTransaction(
        string $cdpUserId,
        string $address,
        string $txHex,
        string $network = 'polygon'
    ): array {
        // Correct endpoint for embedded wallet (end-user) transactions with delegated signing
        // Network names: 'polygon', 'base', 'ethereum', 'avalanche', 'optimism', 'arbitrum', etc.
        $path = '/platform/v2/embedded-wallet-api/end-users/' . urlencode($cdpUserId) . '/evm/send/transaction';
        $body = [
            'address'     => $address,
            'network'     => $network,
            'transaction' => $txHex,
        ];

        return $this->request('POST', $path, $body, true);
    }

    /**
     * Initiate wallet creation by sending an OTP email via CDP.
     * Returns ['ok' => true] on success or ['ok' => false, 'error' => '...'].
     */
    public function initiateWalletOtp(string $email): array
    {
        try {
            $creds = $this->creds();
            if (empty($creds['keyId']) || empty($creds['keySecret'])) {
                return ['ok' => false, 'error' => 'CDP API Key não configurada'];
            }

            $body   = ['user_identifier' => $email, 'network' => 'base-mainnet'];
            $path   = '/platform/v2/evm/embedded-wallets/initiate';
            $data   = $this->request('POST', $path, $body, false);
            $status = $data['__http_status'] ?? 0;

            if ($status === 200 || $status === 201 || $status === 204) {
                return ['ok' => true];
            }

            return [
                'ok'    => false,
                'error' => $data['errorMessage'] ?? ('HTTP ' . $status),
                'raw'   => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('CDP initiateWalletOtp failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Verify OTP and complete wallet creation. Returns wallet address on success.
     */
    public function confirmWalletOtp(string $email, string $otp): array
    {
        try {
            $creds = $this->creds();
            if (empty($creds['keyId']) || empty($creds['keySecret'])) {
                return ['address' => null, 'error' => 'CDP API Key não configurada'];
            }

            $body   = ['user_identifier' => $email, 'otp' => $otp, 'network' => 'base-mainnet'];
            $path   = '/platform/v2/evm/embedded-wallets/confirm';
            $data   = $this->request('POST', $path, $body, false);
            $status = $data['__http_status'] ?? 0;

            if ($status === 200 || $status === 201) {
                $address = $data['address'] ?? ($data['evm_address'] ?? ($data['wallet']['address'] ?? null));
                $userId  = $data['user_id'] ?? ($data['wallet']['user_id'] ?? $email);
                if ($address) {
                    return ['address' => $address, 'user_id' => $userId];
                }
                return ['address' => null, 'error' => 'Endereço não retornado', 'raw' => array_keys($data)];
            }

            return [
                'address' => null,
                'error'   => $data['errorMessage'] ?? ('HTTP ' . $status),
                'raw'     => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('CDP confirmWalletOtp failed', ['error' => $e->getMessage()]);
            return ['address' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create or retrieve a Coinbase CDP delegated wallet for a user.
     * Uses the user's email as the stable identifier.
     *
     * Returns ['address' => '0x...', 'user_id' => '...'] on success.
     * Returns ['address' => null, 'error' => '...'] on failure.
     */
    public function createOrGetWallet(string $email): array
    {
        try {
            $creds = $this->creds();
            if (empty($creds['keyId']) || empty($creds['keySecret'])) {
                return ['address' => null, 'error' => 'CDP API Key não configurada. Configure em Admin → General Settings.'];
            }

            $idempotencyKey = hash('sha256', 'wallet-' . $email);
            $body = ['idempotency_key' => $idempotencyKey];
            $path = '/platform/v2/evm/accounts';
            $data = $this->request('POST', $path, $body, true);

            $status = $data['__http_status'] ?? 0;

            if ($status === 200 || $status === 201) {
                // CDP returns {address: "0x...", network: "..."} or {account: {address: "0x..."}}
                $address = $data['address']
                    ?? ($data['account']['address'] ?? null);

                if ($address) {
                    Log::info('CDP server wallet created', ['email' => $email, 'address' => $address]);
                    return ['address' => $address, 'user_id' => $email];
                }
            }

            Log::warning('CDP createOrGetWallet unexpected response', [
                'status' => $status,
                'keys'   => array_keys($data),
                'raw'    => $data,
            ]);
            return ['address' => null, 'error' => ($data['errorMessage'] ?? 'Unexpected CDP response: HTTP ' . $status)];
        } catch (\Throwable $e) {
            Log::error('CDP createOrGetWallet failed', ['error' => $e->getMessage()]);
            return ['address' => null, 'error' => $e->getMessage()];
        }
    }

    // ─── Polygon on-chain helpers ────────────────────────────────────────────────

    private function polygonRpc(string $method, array $params = []): mixed
    {
        $payload = json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1]);
        $lastError = null;

        foreach (array_merge([self::POLYGON_RPC], self::POLYGON_RPC_FALLBACKS) as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                $lastError = "Polygon RPC curl error: {$err}";
                continue;
            }
            $data = json_decode($res, true);
            if (!is_array($data)) {
                $lastError = "Polygon RPC {$method}: resposta inválida";
                continue;
            }
            if (isset($data['error'])) {
                $lastError = "Polygon RPC {$method} error: " . ($data['error']['message'] ?? json_encode($data['error']));
                Log::warning('Polygon RPC falhou, tentando fallback', ['url' => $url, 'method' => $method, 'error' => $lastError]);
                continue;
            }
            return $data['result'] ?? null;
        }

        throw new \RuntimeException($lastError ?? "Polygon RPC {$method}: falha");
    }

    private function hexWei(string $hex): \GMP
    {
        return gmp_init(ltrim($hex, '0x') ?: '0', 16);
    }

    private function buildErc20TransferData(string $toAddress, \GMP $amountWei): string
    {
        $addr = str_pad(preg_replace('/^0x/i', '', $toAddress), 64, '0', STR_PAD_LEFT);
        $amt  = str_pad(gmp_strval($amountWei, 16), 64, '0', STR_PAD_LEFT);
        return '0x' . 'a9059cbb' . $addr . $amt;
    }

    private function waitForReceipt(string $txHash, int $maxAttempts = 30): bool
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                $receipt = $this->polygonRpc('eth_getTransactionReceipt', [$txHash]);
            } catch (\Throwable $e) {
                // transient RPC error right after broadcast — keep polling
                sleep(2);
                continue;
            }
            if ($receipt !== null) {
                return ($receipt['status'] ?? '0x0') === '0x1';
            }
            sleep(2);
        }
        return false;
    }

    private function sendMaticFromVault(string $toAddress, \GMP $amountWei, string $vaultKey): string
    {
        $g2          = General::first();
        $vaultAddr   = $g2->vault_wallet_address;
        $gasPriceHex = $this->polygonRpc('eth_gasPrice') ?? '0x2540BE400';
        $gasPrice    = $this->hexWei($gasPriceHex);
        $cap300Gwei  = gmp_init('300000000000');
        $maxFee      = gmp_cmp($gasPrice, $cap300Gwei) > 0 ? $cap300Gwei : $gasPrice;
        $priorityFee = gmp_init('30000000000'); // 30 gwei
        $cleanKey    = ltrim($vaultKey, '0x');

        $nonce = hexdec($this->polygonRpc('eth_getTransactionCount', [$vaultAddr, 'latest']) ?? '0x0');

        $buildSigned = function (int $n) use ($toAddress, $amountWei, $maxFee, $priorityFee, $cleanKey): string {
            $tx = new \kornrunner\Ethereum\EIP1559Transaction(
                dechex($n),
                gmp_strval($priorityFee, 16),
                gmp_strval($maxFee, 16),
                dechex(21000),
                $toAddress,
                gmp_strval($amountWei, 16),
                ''
            );
            return '0x' . $tx->getRaw($cleanKey, self::POLYGON_CHAIN);
        };

        try {
            $txHash = $this->polygonRpc('eth_sendRawTransaction', [$buildSigned($nonce)]);
        } catch (\RuntimeException $e) {
            // Some nodes report pending-mempool nonce in the error; retry with the correct one
            if (preg_match('/next nonce[: ]+(\d+)/i', $e->getMessage(), $m)) {
                $nonce  = (int) $m[1];
                $txHash = $this->polygonRpc('eth_sendRawTransaction', [$buildSigned($nonce)]);
            } else {
                throw $e;
            }
        }

        if (empty($txHash)) {
            throw new \RuntimeException('eth_sendRawTransaction não retornou hash.');
        }
        return $txHash;
    }

    /**
     * Ensure the user's CDP wallet has enough MATIC for a FGOL transfer.
     * Tops up from the vault if the shortfall is within allowed bounds.
     */
    private function topUpGasIfNeeded(string $userAddress, string $fgolContract, string $calldata): array
    {
        $balHex      = $this->polygonRpc('eth_getBalance', [$userAddress, 'latest']) ?? '0x0';
        $balance     = $this->hexWei($balHex);

        $gasPriceHex  = $this->polygonRpc('eth_gasPrice') ?? '0x2540BE400';
        $gasPrice     = $this->hexWei($gasPriceHex);
        $cap300Gwei   = gmp_init('300000000000');
        $maxFeePerGas = gmp_cmp($gasPrice, $cap300Gwei) > 0 ? $cap300Gwei : $gasPrice; // cap at 300 gwei

        $gasEstHex   = $this->polygonRpc('eth_estimateGas', [[
            'from' => $userAddress,
            'to'   => $fgolContract,
            'data' => $calldata,
        ]]) ?? '0x15F90'; // 90 000 fallback
        $gasEst      = $this->hexWei($gasEstHex);
        $gasLimit    = gmp_div(gmp_mul($gasEst, gmp_init(13)), gmp_init(10)); // ×1.3

        $needed = gmp_mul($gasLimit, $maxFeePerGas);

        if (gmp_cmp($balance, $needed) >= 0) {
            return ['toppedUp' => false, 'sentMatic' => '0', 'gasLimit' => $gasLimit, 'maxFeePerGas' => $maxFeePerGas];
        }

        $shortfall = gmp_sub($needed, $balance);
        $minWei    = gmp_init(self::MIN_TOPUP_WEI);
        $maxWei    = gmp_init(self::MAX_TOPUP_WEI);

        if (gmp_cmp($shortfall, $minWei) < 0) {
            $shortfall = $minWei;
        }
        if (gmp_cmp($shortfall, $maxWei) > 0) {
            throw new \RuntimeException(sprintf(
                'Gas necessário (~%s MATIC) acima do teto de %.3f MATIC.',
                bcdiv(gmp_strval($needed), '1000000000000000000', 6),
                (float) self::MAX_TOPUP_WEI / 1e18
            ));
        }

        $g       = General::first();
        $vaultKey = trim($g->vault_wallet_private_key ?? '');
        if (empty($vaultKey)) {
            throw new \RuntimeException('vault_wallet_private_key não configurada.');
        }

        $topUpHash = $this->sendMaticFromVault($userAddress, $shortfall, $vaultKey);
        Log::info('CDP gas top-up sent', ['to' => $userAddress, 'hash' => $topUpHash]);

        if (!$this->waitForReceipt($topUpHash)) {
            throw new \RuntimeException("Top-up MATIC tx {$topUpHash} não confirmado a tempo.");
        }

        $sentMatic = bcdiv(gmp_strval($shortfall), '1000000000000000000', 8);
        return ['toppedUp' => true, 'sentMatic' => $sentMatic, 'gasLimit' => $gasLimit, 'maxFeePerGas' => $maxFeePerGas];
    }

    /**
     * Send FGOL from a user's CDP-delegated wallet to any destination.
     * Handles gas top-up, builds the unsigned EIP-1559 tx, and signs via CDP delegation.
     *
     * $toAddress: destination (user's external wallet, treasury, etc.)
     * Returns ['txHash' => '0x...', 'gasMaticFronted' => float]
     */
    public function sendFgolFromUserCdp(
        string $cdpUserId,
        string $userAddress,
        string $toAddress,
        float  $amountFgol
    ): array {
        if (empty($cdpUserId)) {
            throw new \RuntimeException('cdpUserId ausente — carteira criada antes do fluxo de delegação.');
        }
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $toAddress)) {
            throw new \RuntimeException('Endereço de destino inválido.');
        }
        if ($amountFgol <= 0) {
            throw new \RuntimeException('Valor inválido.');
        }

        $g            = General::first();
        $fgolContract = $g->fgol_contract_address ?? '';
        $treasuryAddr = $toAddress; // destination is caller-specified

        if (empty($fgolContract)) throw new \RuntimeException('fgol_contract_address não configurado.');

        // Amount in wei (18 decimals)
        $amountWei = gmp_init(bcmul((string)$amountFgol, '1000000000000000000', 0));

        // 1. Check FGOL balance on-chain
        $balCalldata = '0x70a08231' . str_pad(preg_replace('/^0x/i', '', $userAddress), 64, '0', STR_PAD_LEFT);
        $balHex = $this->polygonRpc('eth_call', [
            ['to' => $fgolContract, 'data' => $balCalldata],
            'latest',
        ]) ?? '0x0';
        $fgolBal = $this->hexWei($balHex);
        if (gmp_cmp($fgolBal, $amountWei) < 0) {
            throw new \RuntimeException(sprintf(
                'Carteira %s tem %.4f FGOL on-chain, precisa de %.4f.',
                $userAddress,
                (float) gmp_strval($fgolBal) / 1e18,
                $amountFgol
            ));
        }

        // 2. Build ERC-20 transfer calldata (to the specified destination)
        $calldata = $this->buildErc20TransferData($toAddress, $amountWei);

        // 3. Top-up gas if needed (also returns gasLimit + maxFeePerGas)
        $topUp      = $this->topUpGasIfNeeded($userAddress, $fgolContract, $calldata);
        $gasLimit   = $topUp['gasLimit'];
        $maxFee     = $topUp['maxFeePerGas'];
        $priorityFee = gmp_init('30000000000'); // 30 gwei

        // 4. Build unsigned EIP-1559 transaction
        $nonceHex = $this->polygonRpc('eth_getTransactionCount', [$userAddress, 'latest']) ?? '0x0';
        $nonce    = hexdec($nonceHex);

        $tx = new \kornrunner\Ethereum\EIP1559Transaction(
            dechex($nonce),
            gmp_strval($priorityFee, 16),
            gmp_strval($maxFee, 16),
            gmp_strval($gasLimit, 16),
            $fgolContract,
            '0',
            $calldata
        );
        $txHex = '0x' . $tx->getUnsigned(self::POLYGON_CHAIN);

        // 5. Sign and broadcast via CDP delegated signing
        $res = $this->sendUserTransaction($cdpUserId, $userAddress, $txHex, 'polygon');
        $status = $res['__http_status'] ?? 0;
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'CDP sendEvmTransaction falhou: ' . ($res['errorMessage'] ?? "HTTP {$status}")
            );
        }

        $hash = $res['transactionHash'] ?? ($res['hash'] ?? ($res['txHash'] ?? null));
        if (empty($hash)) {
            throw new \RuntimeException('CDP não retornou hash da transação: ' . json_encode($res));
        }

        return [
            'txHash'          => $hash,
            'gasMaticFronted' => (float) ($topUp['sentMatic'] ?? 0),
        ];
    }

    /**
     * Asks Coinbase whether the platform really holds an active delegation for this end user + address,
     * by requesting a harmless message signature (no transaction, no funds). Fails closed.
     * Returns ['active' => bool, 'reason' => string].
     */
    public function hasActiveDelegation(string $cdpUserId, string $address): array
    {
        if ($cdpUserId === '' || !preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            return ['active' => false, 'reason' => 'cdp_user_id ausente ou carteira inválida'];
        }
        try {
            $res = $this->request(
                'POST',
                '/platform/v2/embedded-wallet-api/end-users/' . urlencode($cdpUserId) . '/evm/sign/message',
                ['address' => $address, 'message' => 'goldofir delegation check'],
                true
            );
        } catch (\Throwable $e) {
            return ['active' => false, 'reason' => 'não foi possível consultar a Coinbase: ' . $e->getMessage()];
        }
        $status = $res['__http_status'] ?? 0;
        if ($status >= 200 && $status < 300 && !empty($res['signature'])) {
            return ['active' => true, 'reason' => 'ok'];
        }
        return ['active' => false, 'reason' => $res['errorMessage'] ?? "HTTP {$status}"];
    }

    /** Convenience: pull FGOL from user's CDP wallet back to the platform treasury. */
    public function pullFgolFromUser(string $cdpUserId, string $userAddress, float $amountFgol): array
    {
        $vault = General::first()->vault_wallet_address ?? '';
        if (empty($vault)) throw new \RuntimeException('vault_wallet_address não configurado.');
        return $this->sendFgolFromUserCdp($cdpUserId, $userAddress, $vault, $amountFgol);
    }

    // ─── Utility ─────────────────────────────────────────────────────────────────

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
