<?php

namespace Payments;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Log;
use Payments\SDK\Plisio as PlisioClient;

final class Plisio
{
    /**
     * The Plisio client instance.
	 *
     */
	protected PlisioClient $plisioClient;
	 
    protected CurrencyService $currencyService;

    /**
     * Create a new Plisio instance.
     *
     * @param array<string, mixed> $config Configuration array for Stripe.
     *
     * Expected config keys:
     * - crypto_currency: USDT_TRX,USDT,BTC,ETH
     * - plisio_api_key: plisio api key
     * - currency: Currency code (e.g., 'USD')
     * - currency_decimals: Number of decimal places for currency
     */	
    public function __construct(protected array $config)
    {
        $this->currencyService = new CurrencyService();
    }
	
	public function plisioClient():PlisioClient
    {
        $this->plisioClient = new PlisioClient($this->config['plisio_api_key']);
		
		return $this->plisioClient;
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
                'default' => 'Plisio',
                'disabled' => true,
            ],
            'plisio_api_key' => [
                'label' => 'Plisio API Key',
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
					['value' => 'TRX', 'text' => 'TRX'], 
					['value' => 'SOL', 'text' => 'SOL'], 
					['value' => 'BNB', 'text' => 'BNB BSC'], 
					['value' => 'USDT_TRX', 'text' => 'USDT TRC20'], 
					['value' => 'USDT', 'text' => 'USDT ERC20'], 
					['value' => 'USDC', 'text' => 'USDC ERC20'], 
					['value' => 'USDT_SOL', 'text' => 'USDT SOL'],
				],
				'default' => 'USDT_TRX',
                'disabled' => false,
            ],
			'timeout' => [
                'label' => 'ExpireIn (Min)',
                'type' => 'text',
                'default' => 60,
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
     * Create a Plisio payment intent for an order.
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
                abort(422, __("order.missing_key").$key);
            }
        }

        if (!is_numeric($order['total_amount'])) {
            abort(422, __("order.Invalid_amount"));
        }

        $rates = $this->currencyService->fetchRates();
        $defaultCurrency = config('xmplus.default_currency_code', 'USD'); // Fallback default currency
        if (!isset($rates[$this->config['currency']], $rates[$defaultCurrency])) {
            abort(422, __('order.invalid_rate'));
        }

        $value = $rates[$this->config['currency']] / $rates[$defaultCurrency];
        $total = number_format((float) $order['total_amount'] * $value, $this->config['currency_decimals'], '.', '');
		
        try {
			$fiat_rate = 0;
			//$charge = 0;
			
			$currencies = $this->plisioClient()->getCurrencies($this->config['currency']);
			$data = array_filter($currencies, fn($currency) => $currency['currency'] == $this->config['crypto_currency']);
			$data = array_values($data);
			
			if (!empty($data)) {
				$fiat_rate = $data[0]['fiat_rate'];
				//$charge = ($data['invoice_commission_percentage'] / 100) * ($fiat_rate * $total);
			}
			
			$amount = $fiat_rate * $total;
			
			/*
			$cryptoFee = 0;
			$planFee = $this->plisioClient()->transactionFeePlans($this->config['crypto_currency']);
			if($planFee['status'] == 'success' && isset($planFee['normal']['value'])){
				$cryptoFee = $planFee['normal']['value'] / $fiat_rate;
			}
			*/
			$shopInfo = $this->plisioClient()->getShopInfo();
			$isWhiteLabel = $shopInfo['data']['white_label'] ?? false;
			
            $response = $this->plisioClient()->createTransaction([
				'order_name' => $order['description'],
				'order_number' => $order['pay_id'],
				'currency' => $this->config['crypto_currency'],
				'amount' => $amount,
				'expire_min' => (int)$this->config['timeout'] ?? 60,
				//'source_amount' => $total,
				'source_currency' => $this->config['currency'],
				'callback_url' => $this->config['notify_url'],
				'success_callback_url' => $order['return_url'],
				'success_invoice_url' => $order['return_url'],
				'fail_invoice_url' => $order['cancel_url'],
				'fail_callback_url ' => $order['cancel_url'],
				'email' => $order['email'],
				'plugin' => 'laravelSdk',
			]);

            if (isset($response['status']) && $response['status'] == 'success' && isset($response['data'])) {
				return $isWhiteLabel ? [
					'data' => $response['data']['invoice_url'],
					'txn_id' => $response['data']['txn_id'],
					'link' => $response['data']["wallet_hash"] ?? $response['data']['txn_id'],
					'total_amount' => $total,
					'ex_amount' => $response['data']['invoice_total_sum'],
					"wallet_hash" => $response['data']["wallet_hash"],
					"qr_code" => $response['data']["qr_code"],
					"crypto_currency" => $response['data']["psys_cid"],
					'pending_amount' => $response['data']["pending_amount"],
					'amount' => $response['data']["amount"],
					'external' => false,
					'currency' => $this->config['currency'] ?? $response['data']["psys_cid"],
					'remarks' => $this->config['remarks'],
					'expire' => Carbon::createFromTimestamp($response['data']["expire_utc"], 'UTC')->format('Y-m-d H:i'),
				] : [
					'data' => $response['data']['invoice_url'],
					'link' => $response['data']['txn_id'],
					'amount' => $amount,
					'total_amount' => $total,
					'currency' => $this->config['currency'] ?? $defaultCurrency,
					'remarks' => $this->config['remarks'],
					'ex_amount' => $response['data']['invoice_total_sum'],
					'external' => true,
				];
            } else {
                abort(422, $response['data']['message']);
            }
        } catch (\Throwable $e) {
			Log::error('Plisio: ', [
			   'message' => $e->getMessage(),
				'line'=> $e->getLine(),
				'file' => $e->getFile(),
			]);
			abort(422, __('order.plisio.create_failed',['remarks' => $this->config['remarks']]) . $e->getMessage());
        }
    }

	/**
     * Handle Plisio notifications.
     *
     * @param Request $request Paypal payload (JSON string or array).
     * @return array<string, mixed> Notification result with trade_no, callback_no, and code.
     */
    public function notify($request)
    {
		$params = $request->getContent();
		if (is_string($params)) {
            $params = json_decode($params, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
				Log::error('Plisio: Invalid JSON payload: ' . json_last_error_msg());
				return [
					'code' => 400,
					'message' => __('order.plisio.invalid_payload',['remarks' => $this->config['remarks']]) . json_last_error_msg(),
				];
            }
        }
		
		try{
			$verify = $this->plisioClient()->verifyCallbackData($params);
			
			if ($verify && ($params['status'] == 'completed' || $params['status'] == 'mismatch')) {
				return [
					'trade_no'    => $params['order_number'],
					'callback_no' => $params['txn_id'],
					'code' => 200,
				];
			} elseif($params['status'] == 'pending' || $params['status'] == "pending internal" ) {
				return ['code' => 200, 'status' => 'pending'];
			} else {
				return ['code' => 400, 'message' => __('order.plisio.process_failed',['remarks' => $this->config['remarks']])];
			}
		} catch (\Throwable $e) {
			Log::error('Plisio notification failed', ['params' => $params]);
			return [
				'code' => 400,
				'message' => __('order.plisio.notify_failed',['remarks' => $this->config['remarks']]) . $e->getMessage(),
			];
        }
		
		return ['code' => 200, 'status' => 'ok'];
    }
}
