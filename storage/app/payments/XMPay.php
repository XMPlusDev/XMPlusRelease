<?php

namespace Payments;

use Exception;
use Throwable;
use Illuminate\Http\Request;
use App\Services\CurrencyService;
use App\Models\PaymentLog;
use Illuminate\Support\Facades\Log;

final class XMPay
{
    protected CurrencyService $currencyService;
	
    /**
     * Create a new final class XMPay instance.
     *
     * @param array<string, mixed> $config Configuration array for Stripe.
     *
     * Expected config keys:
     * - api_key: API key
     * - qrcode_link: XMPay QrCode links
     * - timeout: Expire time
     * - currency: Currency code (e.g., 'CNY')
     * - currency_decimals: Number of decimal places for currency
     */

    public function __construct(
		protected array $config
	){
		$this->currencyService = new CurrencyService();
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
                'default' => 'XMPay',
                'disabled' => true,
            ],
            'qrcode_type' => [
                'label' => 'Type',
                'type' => 'array',
                'options' => [ 
					[ 'text' => 'Alipay', 'value' => 'alipay' ], 
					['text' => 'WechatPay', 'value' => 'wechatpay' ]
				],
				'default' => 'alipay',
                'disabled' => false,
            ],
            'api_key' => [
                'label' => 'API Key',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'qrcode_link' => [
                'label' => 'QrCode Links',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'timeout' => [
                'label' => 'Timed Out',
                'type' => 'integer',
                'default' => 5,
                'disabled' => false,
            ],
            'currency' => [
                'label' => 'Currency',
                'type' => 'text',
                'default' => 'CNY',
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
     * Create a XMPay payment intent for an order.
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
            $address = $this->getAvailableAddress();
			
			return [
				'data' => $address,
				'link' => ($this->config['qrcode_type'] === "alipay" ? "alipays://platformapi/startapp?saId=10000007&clientVersion=3.7.0.0718&qrcode=".$address : $address),
				'total_amount' => $total,
				'ex_amount' => $total,
				'expire' => now()->addSeconds($this->config['timeout'] * 60),
				'external' => false,
				'currency' => $this->config['currency'],
				'qrcode_type' => $this->config['qrcode_type'],
			];
        } catch (\Throwable $e) {
			Log::error('XMPay processing error: ', [
			  'message' => $e->getMessage(),
			  'file' => $e->getFile(),
			  'line' => $e->getLine(),
			]);
            abort(500, $e->getMessage());
        }
    }

    private function getAvailableAddress(): string
    {
        $paymentLogs = PaymentLog::where('status', 0)->where('payment_method_id', $this->config['id']);
        $inUseAddresses = $paymentLogs->get(['payment_address']);
        $address = array_filter(explode(",", $this->config['qrcode_link']));
        $availableAddresses = array_values(array_diff($address, $inUseAddresses->pluck('payment_address')->toArray()));
        if (count($availableAddresses) <= 0) {
            abort(500, __("order.xmpay.no_link",['remarks' => $this->config['remarks']]));
        }

        return $availableAddresses[array_rand($availableAddresses)];
    }

    /**
     * Handle XMPay notifications.
     *
     * @param Request $request Webhook payload (JSON string or array).
     * @return array<string, mixed> Notification result with trade_no, callback_no, and code.
     */
    public function notify($request): array
    {
		$sign = $request->query('sign');
        $t = $request->query('t');
        $price = $request->query('price');
		
        $str = $price . $t . ($this->config['api_key']);
		
        if ($sign !== hash('md5', $str)) {
			return [
				'code' => 400,
				'message' => __('order.xmpay.mismatch',['remarks' => $this->config['remarks']]),
			];
        }
		
		$date = \Carbon\Carbon::createFromTimestampMs($t);
		$exptime = $date->format('Y-m-d H:i:s');

        $paymentLog = PaymentLog::where('payment_method_id', $this->config['id'])
			->where("status", 0)->where("expire_at", '>', $exptime)->first();

        if (! $paymentLog) {
            return [
				'code' => 400,
				'message' => __('order.xmpay.not_found',['remarks' => $this->config['remarks']]),
			];
        }
		
		if($price < $paymentLog->ex_amount){
			return [ 'code' => 200, 'status' => 'pending' ];
		}

        return [
            'trade_no' => $paymentLog->pay_id,
            'callback_no' => $paymentLog->order_id,
            'code' => 200,
        ];
    }
}
