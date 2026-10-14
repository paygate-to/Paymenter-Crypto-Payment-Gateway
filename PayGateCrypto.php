<?php

namespace Paymenter\Extensions\Gateways\PayGateCrypto;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * PayGate.to crypto payments for Paymenter: Bitcoin, Ethereum, USDT, USDC, Solana, Litecoin,
 * Monero, Zcash and more on PayGate.to's hosted multi-coin page, forwarded instantly to your own
 * wallets.
 *
 * Install: upload the repository ZIP in Admin > Extensions > Upload Extension (or copy the files
 * to extensions/Gateways/PayGateCrypto/), then add the gateway under Admin > Gateways.
 */
#[ExtensionMeta(
    name: 'PayGate.to Crypto Payments',
    description: 'Bitcoin, USDT, USDC, Solana, Monero and more with instant payouts to your own wallets. No KYC.',
    version: '1.0.0',
    author: 'PayGate.to',
    url: 'https://paygate.to/crypto-payment-gateway-no-kyc-instant-payouts/paymenter/',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCI+PGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMzAiIGZpbGw9IiNmNzkzMWEiLz48cGF0aCBmaWxsPSIjZmZmIiBkPSJNNDMuNiAyOC4xYy42LTQtMi40LTYuMS02LjYtNy41bDEuNC01LjUtMy4zLS44LTEuMyA1LjQtMi43LS42IDEuMy01LjQtMy4zLS44LTEuNCA1LjUtMi4xLS41LTQuNi0xLjEtLjkgMy41czIuNC42IDIuNC42YzEuMy4zIDEuNiAxLjIgMS41IDEuOWwtMy43IDE1Yy0uMi40LS42IDEtMS41LjggMCAwLTIuNC0uNi0yLjQtLjZsLTEuNiAzLjggNC4zIDEuMSAyLjQuNi0xLjQgNS42IDMuMy44IDEuNC01LjUgMi43LjctMS40IDUuNSAzLjMuOCAxLjQtNS42YzUuNyAxLjEgMTAgLjYgMTEuOC00LjUgMS41LTQuMS0uMS02LjUtMy04LjEgMi4yLS41IDMuOC0xLjkgNC4zLTQuOXptLTcuNiAxMC43Yy0xIDQuMS04IDEuOS0xMC4yIDEuM2wxLjgtNy4zYzIuMy42IDkuNSAxLjcgOC40IDZ6bTEtMTAuOGMtLjkgMy44LTYuNyAxLjktOC42IDEuNGwxLjYtNi42YzEuOS41IDggMS40IDcgNS4yeiIvPjwvc3ZnPg=='
)]
class PayGateCrypto extends Gateway
{
    public const MODULE_VERSION = '1.0.0';
    private const NAME = 'PayGateCrypto';
    private const CALLBACK_ROUTE = 'extensions.gateways.paygatecrypto.callback';

    /** Network => label; the keys are the networks of PayGate.to's hosted multi-coin page. */
    private const NETWORKS = [
        'evm' => 'EVM wallet: ETH, USDT, USDC… on Ethereum, BNB Chain, Polygon, Arbitrum, Base, Optimism, Avalanche',
        'btc' => 'Bitcoin wallet',
        'ltc' => 'Litecoin wallet',
        'solana' => 'Solana wallet: SOL, USDC, USDT on Solana',
        'trc20' => 'Tron wallet: TRX, USDT (TRC20)',
        'bitcoincash' => 'Bitcoin Cash wallet',
        'doge' => 'Dogecoin wallet',
        'xmr' => 'Monero wallet',
        'zec' => 'Zcash wallet',
        'ton' => 'TON wallet',
    ];

    public function boot()
    {
        Route::get('/extensions/gateways/paygatecrypto/callback', [self::class, 'callback'])->name(self::CALLBACK_ROUTE);
    }

    public function getConfig($values = [])
    {
        $config = [];
        foreach (self::NETWORKS as $network => $label) {
            $config[] = [
                'name' => 'wallet_' . $network,
                'label' => $label,
                'type' => 'text',
                'description' => $network === 'evm' ? 'Self-custodial wallets you control. Leave a network empty to not accept it.' : null,
                'required' => false,
            ];
        }

        return array_merge($config, [
            [
                'name' => 'add_fees',
                'label' => 'The customer pays the network fee',
                'type' => 'checkbox',
                'description' => 'Adds the estimated blockchain fee for forwarding the payment to the amount the customer sends.',
                'required' => false,
            ],
            [
                'name' => 'tolerance',
                'label' => 'Underpayment tolerance',
                'type' => 'select',
                'description' => 'Crypto prices move and some wallets round amounts. Payments within this margin pay the invoice.',
                'options' => ['0.98' => '2% (default)', '0.99' => '1%', '1' => '0%', '0.97' => '3%', '0.95' => '5%', '0.90' => '10%'],
                'default' => '0.98',
                'required' => false,
            ],
            [
                'name' => 'api_domain',
                'label' => 'Dedicated API endpoint (optional)',
                'type' => 'text',
                'description' => 'Your free DDoS-protected endpoint, for example api-yourshop-1a2b3c.paygate.to. Empty = api.paygate.to.',
                'required' => false,
            ],
            [
                'name' => 'checkout_domain',
                'label' => 'Checkout domain (optional)',
                'type' => 'text',
                'description' => 'Your white-label checkout domain. Empty = checkout.paygate.to.',
                'required' => false,
            ],
            [
                'name' => 'theme',
                'label' => 'Checkout colour (optional)',
                'type' => 'text',
                'description' => 'Hex code or CSS colour name for the hosted page, for example #7c3aed.',
                'required' => false,
            ],
        ]);
    }

    /**
     * Prepares the hosted multi-coin page for this invoice and returns its URL (Paymenter redirects to it).
     */
    public function pay(Invoice $invoice, $total)
    {
        $payload = [];
        foreach (array_keys(self::NETWORKS) as $network) {
            $wallet = trim((string) $this->config('wallet_' . $network));
            if ($wallet !== '') {
                $payload[$network] = $wallet;
            }
        }
        if ($payload === [] || $total <= 0) {
            throw new \App\Exceptions\DisplayException(__('This payment method is not available right now.'));
        }
        $payload['fiat_amount'] = round((float) $total, 2);
        $payload['fiat_currency'] = strtoupper((string) $invoice->currency_code);
        $payload['callback'] = self::pgCallbackUrl((int) $invoice->id);

        $hosted = $this->pgRequest('POST', 'https://' . $this->pgApiHost() . '/crypto/multi-hosted-wallet.php', $payload);
        if ($hosted === null || !isset($hosted['payment_token']) || !is_string($hosted['payment_token']) || $hosted['payment_token'] === '') {
            throw new \App\Exceptions\DisplayException(__('Payment could not be started. Please try again in a moment.'));
        }

        $query = 'payment_token=' . self::pgKeepEncoded($hosted['payment_token']) . '&add_fees=' . ($this->config('add_fees') ? '1' : '0');
        $theme = trim((string) $this->config('theme'));
        if ($theme !== '') {
            $query .= '&theme=' . rawurlencode($theme);
        }
        if (trim((string) $this->config('checkout_domain')) !== '') {
            $query .= '&domain=' . rawurlencode($this->pgCheckoutHost());
        }

        return 'https://' . $this->pgCheckoutHost() . '/crypto/hosted.php?' . $query;
    }

    /**
     * PayGate.to's callback: verified, then the invoice is paid when enough of the chosen coin arrived.
     */
    public function callback(Request $request)
    {
        [$invoice, $params, $error] = $this->pgAuthenticate($request);
        if ($error !== null) {
            return $error;
        }
        if (($params['pending'] ?? '0') === '1') {
            return self::pgOk(); // unconfirmed: the confirmed callback follows
        }

        return $this->pgLocked((int) $invoice->id, fn (Invoice $invoice) => $this->settle($invoice, $params));
    }

    private function settle(Invoice $invoice, array $params)
    {
        $txid = trim((string) $params['txid_in']);
        if (self::pgAlreadyRecorded($invoice, $txid)) {
            return self::pgOk();
        }

        // The customer chose the coin on the hosted page, so price the remaining amount in that coin now.
        $coin = str_replace('/', '_', strtolower(trim((string) $params['coin'])));
        $pos = strpos($coin, '_');
        $path = $pos === false ? $coin : substr($coin, 0, $pos) . '/' . substr($coin, $pos + 1);
        if (!preg_match('#^[a-z0-9][a-z0-9.-]*(/[a-z0-9][a-z0-9._-]*)?$#', $path)) {
            $this->pgRecordProblem($invoice, $txid, 'unknown coin ' . $coin);

            return self::pgOk();
        }
        $received = $params['value_coin'] . ' ' . $coin;
        $wallets = [];
        foreach (array_keys(self::NETWORKS) as $network) {
            $wallets[] = (string) $this->config('wallet_' . $network);
        }
        if (!self::pgPaysUs($params, $wallets)) {
            $this->pgRecordProblem($invoice, $txid, 'received ' . $received . ' but the payout did not go to the wallets configured here');

            return self::pgOk();
        }
        if (!$this->pgOpen($invoice, $txid, $received)) {
            return self::pgOk();
        }
        $remaining = (float) $invoice->remaining;
        $conversion = $this->pgRequest('GET', 'https://' . $this->pgApiHost() . '/crypto/' . $path . '/convert.php?value=' . rawurlencode(self::pgAmount($remaining)) . '&from=' . rawurlencode(strtolower((string) $invoice->currency_code)));
        if ($conversion === null || !isset($conversion['value_coin']) || !is_numeric($conversion['value_coin'])) {
            return response('price unavailable', 503)->header('Content-Type', 'text/plain'); // PayGate.to calls again
        }
        $tolerance = is_numeric($this->config('tolerance')) ? max(0.5, min(1.0, (float) $this->config('tolerance'))) : 0.98;
        if ($invoice->items()->where('reference_type', 'App\\Models\\Credit')->exists()) {
            $tolerance = max($tolerance, 0.98); // account credit is paid out in full
        }
        if ((float) $params['value_coin'] < $tolerance * (float) $conversion['value_coin']) {
            $this->pgRecordProblem($invoice, $txid, sprintf('underpaid: received %s %s, %s expected', $params['value_coin'], $coin, $conversion['value_coin']));

            return self::pgOk();
        }

        ExtensionHelper::addPayment($invoice->id, self::NAME, $remaining, transactionId: $txid);

        return self::pgOk();
    }

    // ------------------------------------------------------------------
    // Shared PayGate.to helpers (identical in PayGateCard and PayGateCrypto)
    // ------------------------------------------------------------------

    private function pgApiHost(): string
    {
        return self::pgHost((string) $this->config('api_domain'), 'api.paygate.to');
    }

    private function pgCheckoutHost(): string
    {
        return self::pgHost((string) $this->config('checkout_domain'), 'checkout.paygate.to');
    }

    private static function pgHost(string $value, string $default): string
    {
        $host = strtolower(trim($value));
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = rtrim(explode('/', $host, 2)[0], '.');
        if ($host === '' || !preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return $default;
        }

        return $host;
    }

    /** GET or POST to the PayGate.to API; the decoded JSON object, or null on any failure. */
    private function pgRequest(string $method, string $url, ?array $json = null): ?array
    {
        try {
            $http = Http::timeout(30)->withoutRedirecting()->acceptJson()->withUserAgent('Paymenter-PayGate/' . self::MODULE_VERSION);
            $response = $json === null ? $http->get($url) : $http->post($url, $json);
            if ($response->status() !== 200) {
                return null;
            }
            $data = json_decode($response->body(), true);
        } catch (\Throwable $e) {
            // Never log URLs: the callback URL inside them carries the invoice token.
            Log::warning('PayGate.to request to ' . parse_url($url, PHP_URL_HOST) . ' failed: ' . preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[url]', $e->getMessage()));

            return null;
        }
        if (!is_array($data) || (isset($data['status']) && is_string($data['status']) && strtolower($data['status']) === 'error')) {
            return null;
        }

        return $data;
    }

    /** Values PayGate.to returns already percent-encoded are passed through; anything else is encoded. */
    private static function pgKeepEncoded(string $value): string
    {
        if (preg_match('/%[0-9A-Fa-f]{2}/', $value) && preg_match('/^(?:[A-Za-z0-9._~-]|%[0-9A-Fa-f]{2})+$/', $value)) {
            return $value;
        }

        return rawurlencode($value);
    }

    private static function pgAmount(float $value, int $decimals = 2): string
    {
        $text = number_format($value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** HMAC with a key derived from APP_KEY, so callback parameters cannot be changed from outside. */
    private static function pgHmac(string $data): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is not set.');
        }

        return hash_hmac('sha256', 'paygate|' . self::class . '|' . $data, $key);
    }

    /**
     * The callback route as an absolute URL. Paymenter builds it from its App URL setting (never the
     * Host header of the current request), including any subdirectory.
     */
    private static function pgCallbackBase(): string
    {
        return route(self::CALLBACK_ROUTE);
    }

    /** This invoice's callback URL, carrying the invoice id and its signature. */
    private static function pgCallbackUrl(int $invoiceId, array $extra = []): string
    {
        $url = self::pgCallbackBase() . '?invoice=' . $invoiceId . '&pg_token=' . self::pgHmac('invoice|' . $invoiceId);
        foreach ($extra as $name => $value) {
            $url .= '&' . rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return $url;
    }

    /** The undecoded query string of the request. */
    private static function pgRawQuery(Request $request): string
    {
        $uri = (string) $request->server('REQUEST_URI', '');
        if ($uri !== '') {
            $uri = explode('#', $uri, 2)[0];
            $mark = strpos($uri, '?');

            return $mark === false ? '' : substr($uri, $mark + 1);
        }

        return (string) $request->server('QUERY_STRING', '');
    }

    /** Query parameters; a repeated name keeps its last value (PayGate.to's, which comes last). */
    private static function pgParseQuery(string $raw): array
    {
        $params = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $params[urldecode($eq === false ? $pair : substr($pair, 0, $eq))] = $eq === false ? '' : urldecode(substr($pair, $eq + 1));
        }

        return $params;
    }

    /**
     * Verify PayGate.to's RSA signature over the registered callback URL followed by the raw query
     * string as received. Empty string when valid, otherwise the reason.
     */
    private static function pgVerifySignature(Request $request): string
    {
        $signature = trim((string) $request->header('X-PayGate-Signature', ''));
        if ($signature === '') {
            return 'signature_absent';
        }
        $keyId = trim((string) $request->header('X-PayGate-Key-Id', '')) ?: 'v1';
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            return 'unknown_key_id';
        }
        $pemFile = __DIR__ . '/keys/paygate_callback_public_' . $keyId . '.pem';
        $pem = is_readable($pemFile) ? file_get_contents($pemFile) : false;
        if (!is_string($pem) || $pem === '') {
            return 'unknown_key_id';
        }
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return 'bad_public_key';
        }
        $raw = base64_decode($signature, true);
        if ($raw === false || $raw === '') {
            return 'signature_not_base64';
        }
        $query = self::pgRawQuery($request);
        $signed = $query === '' ? self::pgCallbackBase() : self::pgCallbackBase() . '?' . $query;
        $result = openssl_verify($signed, $raw, $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1 ? '' : ($result === -1 ? 'openssl_error' : 'signature_mismatch');
    }

    /**
     * Common callback checks: [invoice, PayGate.to parameters], or a 403 response.
     *
     * @return array{0: ?Invoice, 1: array<string,string>, 2: ?\Illuminate\Http\Response}
     */
    private function pgAuthenticate(Request $request): array
    {
        $reason = self::pgVerifySignature($request);
        if ($reason !== '') {
            Log::warning(self::NAME . ' callback rejected: ' . $reason);

            return [null, [], response('invalid signature', 403)->header('Content-Type', 'text/plain')];
        }
        $params = self::pgParseQuery(self::pgRawQuery($request));
        $invoiceId = (int) ($params['invoice'] ?? 0);
        if ($invoiceId < 1 || !hash_equals(self::pgHmac('invoice|' . $invoiceId), (string) ($params['pg_token'] ?? ''))) {
            return [null, [], response('invalid invoice token', 403)->header('Content-Type', 'text/plain')];
        }
        $invoice = Invoice::find($invoiceId);
        if (!$invoice || trim((string) ($params['txid_in'] ?? '')) === '' || !isset($params['value_coin'], $params['coin'])) {
            return [null, [], response('unknown invoice or missing payment data', 404)->header('Content-Type', 'text/plain')];
        }

        return [$invoice, $params, null];
    }

    /**
     * Handle one callback per invoice at a time: PayGate.to may deliver the same callback more than
     * once, and simultaneous deliveries must not be credited twice. The invoice is loaded again
     * inside the lock so its remaining amount is current.
     */
    private function pgLocked(int $invoiceId, callable $handler)
    {
        // A row lock on the invoice, held until the payment is recorded: works on every web server
        // and cache setup, unlike a cache lock.
        return DB::transaction(function () use ($invoiceId, $handler) {
            $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->first();

            return $invoice ? $handler($invoice) : response('unknown invoice', 404)->header('Content-Type', 'text/plain');
        });
    }

    /**
     * Whether the invoice can still be paid. Payments for a paid, cancelled or otherwise closed
     * invoice are recorded for the merchant instead of being applied.
     */
    private function pgOpen(Invoice $invoice, string $txid, string $received): bool
    {
        if ((string) $invoice->status === 'pending' && (float) $invoice->remaining > 0) {
            return true;
        }
        $this->pgRecordProblem($invoice, $txid, sprintf('extra payment: the invoice is %s, so %s was not applied; refund the customer or credit their account', (string) $invoice->status, $received));

        return false;
    }

    /**
     * When PayGate.to lists the payout addresses (address_out), at least 90% of the payment must go
     * to the wallets configured here. Without this, a leaked callback URL could be registered with
     * someone else's wallet.
     *
     * @param string[] $wallets
     */
    private static function pgPaysUs(array $params, array $wallets): bool
    {
        if (!isset($params['address_out']) || trim((string) $params['address_out']) === '') {
            return true; // nothing to compare with
        }
        $shares = json_decode((string) $params['address_out'], true);
        if (!is_array($shares)) {
            return false;
        }
        $ours = array_map(static fn ($w) => strtolower(trim((string) $w)), array_filter($wallets, static fn ($w) => trim((string) $w) !== ''));
        $share = 0.0;
        foreach ($shares as $address => $value) {
            if (in_array(strtolower(trim((string) $address)), $ours, true) && is_numeric($value)) {
                $share += (float) $value;
            }
        }

        return $share >= 0.9;
    }

    /** True if this incoming blockchain transaction was already recorded for the invoice. */
    private static function pgAlreadyRecorded(Invoice $invoice, string $txid): bool
    {
        return $invoice->transactions()->where('transaction_id', $txid)->exists();
    }

    /** Record a payment that does not pay the invoice (shown on the invoice as a failed transaction). */
    private function pgRecordProblem(Invoice $invoice, string $txid, string $message): void
    {
        Log::warning(self::NAME . ' invoice #' . $invoice->id . ': ' . $message);
        ExtensionHelper::addPayment($invoice->id, self::NAME, 0, transactionId: $txid, status: InvoiceTransactionStatus::Failed);
    }

    private static function pgOk(): \Illuminate\Http\Response
    {
        return response('ok', 200)->header('Content-Type', 'text/plain');
    }
}
