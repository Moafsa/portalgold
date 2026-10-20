<?php

namespace App\Services;

use App\General;
use Illuminate\Support\Facades\Log;

class AIVerificationService
{
    private string $apiUrl = 'https://api.openai.com/v1/chat/completions';

    private function apiKey(): string
    {
        // Reads from admin settings (generals table), falls back to env
        try {
            $general = General::first();
            if ($general && !empty($general->openai_api_key)) {
                return $general->openai_api_key;
            }
        } catch (\Throwable $e) {
            // DB not available
        }
        return env('OPENAI_API_KEY', '');
    }

    public function verifyDocuments(string $selfiePath, string $identityPath, string $fullName = "", string $documentNumber = ""): array
    {
        $apiKey = $this->apiKey();

        if (empty($apiKey)) {
            Log::warning('OpenAI API Key não configurada — acesse Admin > Website > General Settings');
            return ['selfie' => 0, 'identity' => 0, 'reason' => 'API Key não configurada'];
        }

        try {
            $selfieB64   = $this->imageToBase64($selfiePath);
            $identityB64 = $this->imageToBase64($identityPath);

            if (!$selfieB64 || !$identityB64) {
                return ['selfie' => 0, 'identity' => 0, 'reason' => 'Não foi possível ler os arquivos de imagem'];
            }

            $selfieType   = $this->mimeType($selfiePath);
            $identityType = $this->mimeType($identityPath);

            $payload = [
                'model'      => 'gpt-4o',
                'max_tokens' => 512,
                'messages'   => [
                    [
                        'role'    => 'system',
                        'content' => 'Você é um sistema de verificação KYC. Analise as imagens enviadas e responda SOMENTE com um JSON válido neste formato exato: {"selfie_ok": true/false, "identity_ok": true/false, "face_match": true/false, "name_match": true/false, "doc_number_match": true/false, "name_on_document": "...", "selfie_reason": "...", "identity_reason": "..."}. Sem texto fora do JSON.',
                    ],
                    [
                        'role'    => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Regras de verificação KYC. Responda SOMENTE JSON no formato informado no system prompt.

Critérios:
- selfie_ok: true APENAS se mostra rosto humano real, olhos abertos, sem máscara. false se não for uma selfie real.
- identity_ok: true APENAS se mostra documento oficial com foto e texto legíveis (RG, CNH, CNH-e, Passaporte, CNI). NÃO avalie validade/vencimento — isso é verificado manualmente pela equipe.
- face_match: true se o rosto da SELFIE é claramente a mesma pessoa da foto no DOCUMENTO. Em caso de dúvida, marque false (revisão manual).
- name_match: compare o nome cadastrado "' . $fullName . '" com o nome IMPRESSO NO DOCUMENTO. true somente se for a mesma pessoa (ignore maiúsculas, acentos, abreviações de nomes do meio e ordem dos sobrenomes). false se o nome do documento for de outra pessoa ou claramente diferente, mesmo que o rosto pareça igual. Se o nome cadastrado estiver vazio, marque true.
- doc_number_match: true se o número "' . $documentNumber . '" aparece no documento (CPF, RG, registro, número do passaporte — ignore pontos, traços e espaços). false se não aparece. Se o número cadastrado estiver vazio, marque true.
- name_on_document: o nome completo exatamente como está impresso no documento (para auditoria).
- selfie_reason: explicação em português, máximo 1 frase.
- identity_reason: explicação em português, máximo 1 frase. Foque na qualidade e legibilidade, não na validade.

A PRIMEIRA imagem é a SELFIE. A SEGUNDA imagem é o DOCUMENTO DE IDENTIDADE.',
                            ],
                            [
                                'type'      => 'image_url',
                                'image_url' => [
                                    'url'    => 'data:' . $selfieType . ';base64,' . $selfieB64,
                                    'detail' => 'low',
                                ],
                            ],
                            [
                                'type'      => 'image_url',
                                'image_url' => [
                                    'url'    => 'data:' . $identityType . ';base64,' . $identityB64,
                                    'detail' => 'high',
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $result   = null;
            $httpCode = 0;
            $content  = '';
            // OpenAI sometimes refuses intermittently ("I'm sorry, I can't assist") — retry before falling back
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $ch = curl_init($this->apiUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($payload),
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey,
                    ],
                    CURLOPT_TIMEOUT => 60,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    Log::error('OpenAI Vision API erro', ['code' => $httpCode, 'response' => $response, 'attempt' => $attempt]);
                    continue;
                }

                $data    = json_decode($response, true);
                $content = $data['choices'][0]['message']['content'] ?? '{}';
                $content = preg_replace('/```(?:json)?\s*|\s*```/', '', trim($content));
                $result  = json_decode($content, true);
                if (is_array($result)) break;

                Log::warning('OpenAI Vision: resposta inesperada', ['raw' => $content, 'attempt' => $attempt]);
                $result = null;
            }

            if (!is_array($result)) {
                // Never auto-reject when the AI could not conclude — send to manual review with a clear reason
                $msg = 'A análise automática não conseguiu concluir. Sua verificação foi enviada para revisão manual da equipe.';
                return [
                    'selfie' => 0, 'identity' => 0, 'manual_review' => true,
                    'ai_unavailable' => true,
                    'selfie_reason' => '', 'identity_reason' => $msg,
                    'reason' => $msg,
                ];
            }

            $selfieOk   = $result['selfie_ok'] ?? false;
            $identityOk = $result['identity_ok'] ?? false;
            $faceMatch  = $result['face_match'] ?? false;

            // Decision logic following GolPay principle: "em dúvida, revisão manual, nunca rejeita à toa"
            // APPROVE (1): selfie OK + document valid + face clearly matches
            // MANUAL_REVIEW (0): face uncertain — human reviews
            // REJECT (2): document clearly invalid/unreadable OR selfie has no real face
            // NOTE: document expiry is NOT checked by AI — unreliable on Brazilian docs (CNH-e, RG).
            //       Admin verifies expiry manually during review.
            $nameMatch = $result['name_match'] ?? false;
            $docMatch  = $result['doc_number_match'] ?? false;

            if (!$identityOk) {
                $identityStatus = 2; // invalid/unreadable document → reject
                $identityReason = $result['identity_reason'] ?? '';
            } elseif (!$faceMatch) {
                $identityStatus = 0;
                $identityReason = 'Rosto da selfie não coincide claramente com o documento — aguarda revisão manual da equipe.';
            } elseif (!$nameMatch) {
                $identityStatus = 0;
                $identityReason = 'O nome no documento não confere com o nome informado no cadastro — aguarda revisão manual da equipe.';
            } elseif (!$docMatch) {
                $identityStatus = 0;
                $identityReason = 'O número do documento informado não foi encontrado no documento enviado — aguarda revisão manual da equipe.';
            } else {
                $identityStatus = 1; // approved: legible + face + name + number all match
                $identityReason = $result['identity_reason'] ?? '';
            }

            return [
                'selfie'           => $selfieOk ? 1 : 2,
                'identity'         => $identityStatus,
                'face_match'       => $faceMatch,
                'name_match'       => $nameMatch,
                'doc_number_match' => $docMatch,
                'name_on_document' => $result['name_on_document'] ?? '',
                'selfie_reason'    => $result['selfie_reason'] ?? '',
                'identity_reason'  => $identityReason,
                'manual_review'    => ($identityStatus === 0),
            ];
        } catch (\Throwable $e) {
            Log::error('AIVerificationService exception', ['error' => $e->getMessage()]);
            return ['selfie' => 0, 'identity' => 0, 'reason' => $e->getMessage()];
        }
    }

    private function imageToBase64(string $filename): ?string
    {
        $full = base_path('assets/images/profile/' . basename($filename));
        if (!file_exists($full)) {
            return null;
        }
        return base64_encode(file_get_contents($full));
    }

    private function mimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match($ext) {
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
