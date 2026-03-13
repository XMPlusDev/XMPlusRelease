<?php

namespace Payments;

use App\Services\CurrencyService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class EPay
{
    protected CurrencyService $currencyService;

    /**
     * Create a new EPay instance.
     *
     * @param array<string, mixed> $config Configuration array for EPay.
     * @throws \Throwable If required config keys are missing.
     *
     * Expected config keys:
     * - epay_pid: app id
     * - epay_key: app key
     * - epay_type: wechat or alipay
     * - epay_api_url: apiurl
     * - currency: Currency code (e.g., 'USD')
     * - currency_decimals: Number of decimal places for currency
     */
    public function __construct(protected array $config)
    {
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
                'default' => 'EPay',
                'disabled' => true,
            ],
            'epay_pid' => [
                'label' => 'EPay PID',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'epay_key' => [
                'label' => 'EPay Key',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'epay_type' => [
                'label' => 'Type',
                'type' => 'array',
                'options' => [
                    ['value' => 'aliapy', 'text' => 'Aliapy'],
					['value' => 'wechat', 'text' => 'Wechatpay'],
				],
                'default' => 'aliapy',
                'disabled' => false,
            ],
			'epay_api_url' => [
                'label' => 'API URL',
                'type' => 'text',
                'default' => '',
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
     * Create a EPay payment intent for an order.
     *
     * @param array<string, mixed> $order Order details.
     * @return array<string, mixed> Payment intent details.
     *
     * Expected order keys:
     * - total_amount: The order amount
     * - pay_id: Unique payment identifier
     */
	public function pay($order): array
    {
        $requiredKeys = ['total_amount', 'pay_id', 'return_url', 'cancel_url'];
        foreach ($requiredKeys as $key) {
            if (!isset($order[$key])) {
                abort(500, __("order.missing_key").$key);
            }
        }

        if (!is_numeric($order['total_amount'])) {
            abort(500, __("order.Invalid_amount"));
        }

        $rates = $this->currencyService->fetchRates();
        $defaultCurrency = config('xmplus.default_currency_code', 'USD'); // Fallback default currency
        if (!isset($rates[$this->config['currency']], $rates[$defaultCurrency])) {
            abort(500, __('order.invalid_rate'));
        }

        $value = $rates[$this->config['currency']] / $rates[$defaultCurrency];
        $total = number_format((float) $order['total_amount'] * $value, $this->config['currency_decimals'], '.', '');
		
		try{
			$params = [
				'money' => $total,
				'name' => $order['description'],
				'notify_url' => $this->config['notify_url'],
				'return_url' => $this->config['return_url'],
				'out_trade_no' => $order['pay_id'],
				'pid' => $this->config['epay_pid'],
				"type" =>  $this->config['epay_type']
			];

			$params['sign'] = $this->generateSign($params, $this->config['epay_key']);
			$params['sign_type'] = strtoupper(trim('SHA256'));
			$payment_url = $this->config['epay_api_url']."/submit.php?".http_build_query($params);

			return [
				'data' => $payment_url,
				"link" => $payment_url,
				'notify_url' => $this->config['notify_url'],
				'return_url' => $order['return_url'],
				'cancel_url' => $order['cancel_url'],
				'total_amount' => $total,
				'external' => true,
			];
		}catch(\Throwable $e){
			Log::error('Epay failed: '.$e->getMessage());
			abort(500, $e->getMessage());
		}
    }

    /**
     * Handle EPay notifications.
     *
     * @param Request $request EPay payload (JSON string or array).
     * @return array<string, mixed> Notification result with trade_no, callback_no, and code.
     */
    public function notify($request): array
    {
        $params = $request->getContent();
		if (is_string($params)) {
            $params = json_decode($params, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
				Log::error('EPay: Invalid JSON payload: ' . json_last_error_msg());
				return [
					'code' => 400,
					'message' => __('order.epay.invalid_payload',['remarks' => $this->config['remarks']]) . json_last_error_msg(),
				];
            }
        }
		
        if (!$this->verifyCallbackData($params)) {
            Log::error('EPay could not verify callback data: ' . $params);
            return ['code' => 400, 'message' => __('order.epay.verify_failed',['remarks' => $this->config['remarks']])];
        }
		
		$data = $this->checkOrderStatus($params['out_trade_no']);
		
        $result  = json_decode($data, true);
        if ($result['code'] == 1 && $result['status'] == 1) {
            return [
                'trade_no' => $params['out_trade_no'],
                'callback_no' => $params['trade_no'],
                'code' => 200,
            ];
        } else {
			Log::error('EPay could not process notification: ' . $params);
            return ['code' => 400, 'message' => __('order.epay.notify_failed',['remarks' => $this->config['remarks']])];
        }
    }
	
	/**
	*
	*@param array<string, mixed> $params
	*@return bool
	*/
    private function verifyCallbackData($params): bool
    {
        if (empty($params)) {
            return false;
        }

        $sign = $this->getSign($params);

        if ($sign === $params['sign']) {
            $signResult = true;
        } else {
            $signResult = false;
        }

        return $signResult;
    }

	/**
	*
	*@param string $out_trade_no
	*@return string
	*/
    private function checkOrderStatus($out_trade_no): string
    {
		$data = [
			'act' => 'order',
			'pid' => $this->config['epay_pid'],
			'key' => $this->config['epay_key'],
			'out_trade_no' => $out_trade_no,
		];
		
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => $this->config['epay_api_url'].'/api.php?'.http_build_query($data),
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 30,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'opathic',
        ));

        $res = curl_exec($curl);
        curl_close($curl);
        return $res;
    }

	/**
	*
	*@param array<string, mixed> $data
	*@param string $privateKey
	*@return string
	*/
    private function generateSign($data, $privateKey): string
    {
        if (isset($data['sign'])) {
            unset($data['sign']);
        }
        ksort($data);
        $signSrc = "";
        foreach ($data as $k => $v) {
            if (empty($v) || $v == "") {
                unset($data[$k]);
            } else {
                $signSrc .= $k . '=' . $v . '&';
            }
        }
        $signSrc = trim($signSrc, '&') . $privateKey;
		
        return hash('sha256', $signSrc);
    }

	/**
	*
	*@param array<string, mixed> $param
	*@return string
	*/
    private function getSign($param): string
    {
        ksort($param);
        reset($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k != "sign" && $k != "sign_type" && $v != '') {
                $signstr .= $k.'='.$v.'&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        $signstr .= $this->config['epay_key'];
        $sign = hash('sha256', $signstr);
        return $sign;
    }
}