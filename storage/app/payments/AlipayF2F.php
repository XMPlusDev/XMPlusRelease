<?php

namespace Payments;

use Payments\SDK\AlipayF2F as AlipayClient;
use Exception;
use Throwable;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

final class AlipayF2F
{
    /**
     * The Alipay client instance.
     *
     */
    protected mixed $alipayClient;

    protected CurrencyService $currencyService;

    /**
     * Create a new alipayClient instance.
     *
     * @param array<string, mixed> $config Configuration array for alipayClient.
     *
     * Expected config keys:
     * - app_id: alipay app id
     * - private_key: private key
     * - public_key: public key
     * - currency: Currency code (e.g., 'USD')
     * - currency_decimals: Number of decimal places for currency
     */
    public function __construct(protected array $config)
    {
        $this->currencyService = new CurrencyService();
    }
	
	private function alipayClient():mixed
    {
        $this->alipayClient = new AlipayClient();
		$this->alipayClient->setMethod('alipay.trade.precreate');
        $this->alipayClient->setAppId($this->config['app_id']);
        $this->alipayClient->setPrivateKey($this->config['private_key']);
        $this->alipayClient->setAlipayPublicKey($this->config['public_key']);
        $this->alipayClient->setNotifyUrl($this->config['notify_url']);
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
                'default' => 'Alipay',
                'disabled' => true,
            ],
            'app_id' => [
                'label' => 'App Id',
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
     * Create a Alipay payment intent for an order.
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
            throw new \InvalidArgumentException(__("order.invalid_rate"));
        }

        $value = $rates[$this->config['currency']] / $rates[$defaultCurrency];
        $total = number_format((float) $order['total_amount'] * $value, $this->config['currency_decimals'], '.', '');
		
        try {
            $request = $this->alipayClient()->setBizContent([
                'subject' => $order['description'],
                'out_trade_no' => $order['pay_id'],
                'total_amount' => $total
            ]);
			
            $aliResponse = $request->send();
			
            return [
                'data' => $aliResponse->getQrCodeUrl(),
                'total_amount' => $total,
                'currency' => $this->config['currency'],
                'link' => "alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=".$aliResponse->getQrCodeUrl(),
				'notify_url' => $this->config['notify_url'],
				'return_url' => $order['return_url'],
				'cancel_url' => $order['cancel_url'],
				'external' => false,
				'ex_amount' => $total,
            ];

        } catch (\Throwable $e) {
			Log::error('AlipayF2F: error: ' . $e);
            throw new Exception(__('order.alipay.create_failed',['remarks' => $this->config['remarks']]) .$e->getMessage());
        }
    }

    /**
     * Handle Alipay notifications.
     *
     * @param Request $request Alipay payload (JSON string or array).
     * @return array<string, mixed> Notification result with trade_no, callback_no, and code.
     */
    public function notify($request)
    {
		$params = $request->getContent();
		if (is_string($params)) {
            $params = json_decode($params, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
				Log::error('Alipay: Invalid JSON payload: ' . json_last_error_msg());
				return [
					'code' => 400,
					'message' => __('order.alipay.invalid_payload',['remarks' => $this->config['remarks']]) . json_last_error_msg(),
				];
            }
        }
		
        if ($params['trade_status'] !== 'TRADE_SUCCESS') {
			Log::error('Alipay: Invalid trade status: ' . $params);
			return [
				'code' => 400,
				'message' => __('order.alipay.invalid_trade',['remarks' => $this->config['remarks']]) . $params,
			];
        }

        try {
            if ($this->alipayClient()->verify($params)) {
                return [
                    'trade_no' =>   $params['out_trade_no'],
                    'callback_no' => $params['trade_no'],
                    'code' => 200,
                ];
            } else {
				Log::error('Alipay notification failed', ['params' => $params]);
                return ['code' => 400, 'message' => __('order.alipay.process_failed',['remarks' => $this->config['remarks']])];
            }
        } catch (\Throwable $e) {
			Log::error('Alipay could not process callback notification: '.$e->getMessage());
            return ['code' => 400, 'message' => __('order.alipay.notify_failed',['remarks' => $this->config['remarks']]).$e->getMessage()];
        }
    }
}