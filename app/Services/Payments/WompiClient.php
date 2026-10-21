<?php

namespace App\Services\Payments;

use App\Models\Payments\GatewayTransaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Integración con Wompi (https://docs.wompi.co):
 *  · Web Checkout: el cliente se redirige a checkout.wompi.co con la referencia, el valor en
 *    centavos y la firma de integridad SHA-256(referencia + centavos + moneda + [vencimiento] + secreto).
 *  · Eventos: cada notificación trae signature.properties y signature.checksum =
 *    SHA-256(valores de las propiedades + timestamp + secreto de eventos).
 *  · API: consulta de una transacción por id (y por referencia con la llave privada).
 * Ninguna llave se guarda en la base de datos: viven en el .env (WOMPI_*).
 */
class WompiClient
{
    public function isConfigured(): bool
    {
        return filled($this->config('public_key')) && filled($this->config('integrity_secret')) && filled($this->config('events_secret'));
    }

    /** URL del Web Checkout para una transacción PENDIENTE. */
    public function checkoutUrl(GatewayTransaction $transaction, array $customer, string $redirectUrl): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('El pago en línea no está configurado (llaves de Wompi).');
        }

        $cents = $this->cents((float) $transaction->amount);
        $expiration = $transaction->requested_at->copy()->addMinutes((int) $this->config('expiration_minutes'))->utc()->format('Y-m-d\TH:i:s.v\Z');

        $params = array_filter([
            'public-key'               => $this->config('public_key'),
            'currency'                 => $transaction->currency,
            'amount-in-cents'          => $cents,
            'reference'                => $transaction->internal_reference,
            'signature:integrity'      => $this->integritySignature($transaction->internal_reference, $cents, $transaction->currency, $expiration),
            'expiration-time'          => $expiration,
            'redirect-url'             => $redirectUrl,
            'customer-data:email'      => $customer['email'] ?? null,
            'customer-data:full-name'  => $customer['full_name'] ?? null,
            'customer-data:phone-number' => $customer['phone'] ?? null,
            'customer-data:legal-id'   => $customer['legal_id'] ?? null,
            'customer-data:legal-id-type' => $customer['legal_id_type'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return rtrim($this->config('checkout_url'), '/').'/?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function integritySignature(string $reference, int $amountInCents, string $currency, ?string $expiration = null): string
    {
        return hash('sha256', $reference.$amountInCents.$currency.($expiration ?? '').$this->config('integrity_secret'));
    }

    /** RN-17: autenticidad del evento según su firma (properties + timestamp + secreto). */
    public function verifyEvent(array $event): bool
    {
        $properties = data_get($event, 'signature.properties');
        $checksum = (string) data_get($event, 'signature.checksum');
        $timestamp = data_get($event, 'timestamp');

        if (! is_array($properties) || $checksum === '' || $timestamp === null || blank($this->config('events_secret'))) {
            return false;
        }

        $values = implode('', array_map(fn ($p) => (string) data_get($event['data'] ?? [], $p), $properties));

        return hash_equals(strtolower(hash('sha256', $values.$timestamp.$this->config('events_secret'))), strtolower($checksum));
    }

    /** Consulta directa al API (canal autenticado por TLS). Devuelve data.transaction o null. */
    public function transaction(string $externalId): ?array
    {
        return $this->get('/transactions/'.rawurlencode($externalId));
    }

    /** Busca por nuestra referencia; requiere la llave privada (WOMPI_PRIVATE_KEY). */
    public function transactionByReference(string $reference): ?array
    {
        if (blank($this->config('private_key'))) {
            return null;
        }
        $data = $this->get('/transactions?reference='.rawurlencode($reference), $this->config('private_key'));

        return is_array($data) && array_is_list($data) ? ($data[0] ?? null) : $data;
    }

    public function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    // ── Internos ───────────────────────────────────────────────────────────
    private function get(string $path, ?string $token = null): ?array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->when($token, fn ($h) => $h->withToken($token))
                ->get(rtrim($this->apiUrl(), '/').$path);
        } catch (\Throwable $e) {
            Log::warning('Wompi no respondió: '.$e->getMessage());

            return null;
        }

        return $response->successful() ? $response->json('data') : null;
    }

    private function apiUrl(): string
    {
        return $this->config('environment') === 'production' ? 'https://production.wompi.co/v1' : 'https://sandbox.wompi.co/v1';
    }

    private function config(string $key): mixed
    {
        return config("lothub.wompi.{$key}");
    }
}
