<?php

namespace Payments;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Log;
use Payments\SDK\CoinPayments as CoinPaymentClient;

final class CoinPayments
{
    /**
     * The CoinPayments client instance.
     *
     */
    protected CoinPaymentClient $coinPaymentClient;

    protected CurrencyService $currencyService;

    /**
     * Create a new CoinPayments instance.
     *
     * @param array<string, mixed> $config Configuration array for Stripe.
     * @throws \Throwable If required config keys are missing.
     *
     * Expected config keys:
     * - ipn_secret: live or sandbox
     * - private_key: paypal ckient key
     * - public_key: paypal secret
     * - currency: Currency code (e.g., 'USD')
     * - currency_decimals: Number of decimal places for currency
     */
    public function __construct(protected array $config)
    {
        $this->currencyService = new CurrencyService();
    }
	
	private function coinPaymentClient()
    {
        $this->coinPaymentClient = new CoinPaymentClient(
            $this->config['private_key'],
            $this->config['public_key'],
        );
    }

    /**
     * Return the payment form configuration.
     *
     * @return array<string, array<string, mixed>> The form configuration.
     */
    public function paymentForm(): array
    {
        return [
            'name' => [
                'label' => 'Payment Method',
                'type' => 'text',
                'default' => 'CoinPayments',
                'disabled' => true,
            ],
            'merchant_id' => [
                'label' => 'Merchat Id',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'ipn_secret' => [
                'label' => 'IPN Secret',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'private_key' => [
                'label' => 'Private Key',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'public_key' => [
                'label' => 'Public Key',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'crypto_currency' => [
                'label' => 'Crypto Currency',
                'type' => 'array',
                'options' => [
					['value' => 'BTC', 'text' => 'BTC'], 
					['value' => 'ETH', 'text' => 'ETH'], 
					['value' => 'XRP', 'text' => 'XRP'], 
					['value' => 'TRX', 'text' => 'TRX'], 
					['value' => 'SOL', 'text' => 'SOL'], 
					['value' => 'BNB.BSC', 'text' => 'BNB BSC'], 
					['value' => 'USDT.TRC20', 'text' => 'USDT TRC20'], 
					['value' => 'USDT.ERC20', 'text' => 'USDT ERC20'], 
					['value' => 'USDC.ERC20', 'text' => 'USDC ERC20'], 
					['value' => 'USDT.SOL', 'text' => 'USDT SOL']
				],
				'default' => 'USDT.TRC20',
                'disabled' => false,
            ],
            'currency' => [
                'label' => 'Currency',
                'type' => 'text',
                'default' => 'USD',
                'disabled' => false,
            ],
            'currency_decimals' => [
                'label' => 'Decimal Places',
                'type' => 'integer',
                'default' => 2,
                'disabled' => false,
            ],
        ];
    }

    /**
     * Create a CoinPayments payment intent for an order.
     *
     * @param array<string, mixed> $order Order details.
     * @return array<string, mixed> Payment intent details.
     *
     * Expected order keys:
     * - total_amount: The order amount
     * - pay_id: Unique payment identifier
     */
	public function pay(array $order): array
    {
        $requiredKeys = ['total_amount', 'pay_id', 'return_url', 'cancel_url'];
        foreach ($requiredKeys as $key) {
            if (!isset($order[$key])) {
                throw new \InvalidArgumentException(__("order.missing_key").$key);
            }
        }

        if (!is_numeric($order['total_amount'])) {
            throw new \InvalidArgumentException(__("order.Invalid_amount"));
        }

        $rates = $this->currencyService->fetchRates();
        $defaultCurrency = config('xmplus.default_currency_code', 'USD'); // Fallback default currency
        if (!isset($rates[$this->config['currency']], $rates[$defaultCurrency])) {
            throw new \InvalidArgumentException(__('order.invalid_rate'));
        }

        $value = $rates[$this->config['currency']] / $rates[$defaultCurrency];
        $total = number_format((float) $order['total_amount'] * $value, $this->config['currency_decimals'], '.', '');
		
		try {
			$result = $this->coinPaymentClient()->CreateTransaction([
				'amount' 	=> $total,
				'currency1' => $this->config['currency'],
				'currency2' => $this->config['crypto_currency'],
				'buyer_email' => $order['email'],
				'item_name' => $order['description'],
				'item_number'  => $order['pay_id'],
				'cancel_url' => $order['cancel_url'],
				'success_url' => $order['return_url'],
				'ipn_url' => $this->config['notify_url'],
			]);
			
			if ($result['error'] == 'ok') {
				return [
					'data' => $result['result']['checkout_url'],
					'link' => $result['result']['address'],
					'status_url' => $order['status_url'],
					'notify_url' => $this->config['notify_url'],
					'return_url' => $order['return_url'],
					'cancel_url' => $order['cancel_url'],
					'total_amount' => $total,
					'currency' => $this->config['currency'] ?? $defaultCurrency,
					'amount' => sprintf('%.08f', $result['result']['amount'])." {$this->config['crypto_currency']}",
					'expire' =>  Carbon::createFromTimestamp(now()->addSeconds($result['result']['timeout']), 'UTC')->format('Y-m-d H:i'),
					'external' => false,
					'ex_amount' => $result['result']['amount'],
					'crypto_currency' => $this->config['crypto_currency'],
					'remarks' => $this->config['remarks'],
				];
			} else {
				abort(422, $result['error']);
			}
		} catch (\Throwable $e) {
			Log::error('CoinPayments Error: ' . $e->getMessage(), [
                'order' => $order,
                'config_id' => $this->config['id'] ?? null
            ]);
			throw new \Exception(__('order.coinpayments.create_failed',['remarks' => $this->config['remarks']]) . $e->getMessage());
        }
    }

	/**
     * Handle CoinPayments notifications.
     *
     * @param Request $request CoinPayments payload (JSON string or array).
     * @return array<string, mixed> Notification result with trade_no, callback_no, and code.
     */
    public function notify($request)
    {
		$params = $request->getContent();
		
        if (!$request->has('merchant') || $request->merchant != $this->config['merchant_id']) {
            Log::error('CoinPayments: Invalid merchant ID');
            return [
				'code' => 400,
				'message' => __('order.coinpayments.invalid_merchant',['remarks' => $this->config['remarks']]),
			];
        }

		//ksort($params);
        //reset($params);
        //$data = stripslashes(http_build_query($params));
		
        $hmac = hash_hmac('sha512', $params, $this->config['ipn_secret']);
        if ($hmac != $request->header('HMAC')) {
            Log::error('CoinPayments: Invalid HMAC signature');
            return [
				'code' => 400,
				'message' => __('order.coinpayments.invalid_signature',['remarks' => $this->config['remarks']]),
			];
        }

        $status = $request->status;
		
		if (is_string($params)) {
            $params = json_decode($params, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
				Log::error('CoinPayments: Invalid JSON payload: ' . json_last_error_msg());
				return [
					'code' => 400,
					'message' => __('order.coinpayments.invalid_payload',['remarks' => $this->config['remarks']]) . json_last_error_msg(),
				];
            }
        }
		
        if ($status >= 100 || $status == 2) {
            return [
                'trade_no' => $params['item_number'],
                'callback_no' => $params['txn_id'],
                'code' => 200,
                'custom_result' => 'IPN OK',
            ];
        } else {
			Log::error("CoinPayments: Payment failed for order: {$request->item_number}, Transaction ID: {$request->txn_id}");
            return [
				'code' => 400, 
				'message' => __("order.coinpayments.notify_failed", [
					'orderNo' => $request->item_number,
					'transactionID' => $request->txn_id,
					'remarks' => $this->config['remarks']
				]) 
			];
        }
    }
}
