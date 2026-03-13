<?php

namespace Payments;

use App\Models\Order;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class Zarinpal
{
    protected CurrencyService $currencyService;

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
                'default' => 'Zarinpal',
                'disabled' => true,
            ],
            'zarinpal_merchant' => [
                'label' => 'Merchant ID',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'currency' => [
                'label' => 'Currency',
                'type' => 'text',
                'default' => 'IRR',
                'disabled' => false,
            ],
            'currency_decimals' => [
                'label' => 'Decimal Places',
                'type' => 'integer',
                'default' => 0,
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
                "merchant_id" => $this->config['zarinpal_merchant'],
                "amount" => $total,
                "callback_url" => $this->config['notify_url'],
                "description" => (string)$order['description'],
                "currency" => $this->config['currency'],
                "metadata" => [
                    "order_id" => $order['pay_id'],
                    "email" => $order['email']
                ]
            ];
			
			$result = $this->_curlpost('request', $params);
			
			if (empty($result['errors']) && $result['data']['code'] == 100) {
				return [
				    'txn_id' => $result['data']["authority"],
					'data' => "https://www.zarinpal.com/pg/StartPay/".$result['data']["authority"],
					"link" => "https://www.zarinpal.com/pg/StartPay/".$result['data']["authority"],
					'notify_url' => $this->config['notify_url'],
					'total_amount' => $total,
					'external' => true,
				];
			}else {
                abort(500, $result['errors']['code']. " - ". $result['errors']['message'].' '.json_encode($result['errors']['validations']));
            }
		}catch(\Throwable $e){
			Log::error('Zarinpal failed: '.$e->getMessage());
			abort(500, $e->getMessage());
		}
    }
	
	/**
     * @return array<string, mixed> Notification result with trade_no, callback_no, and code.
     */
	public function notify($request): array
    {
        $params = $request->getContent();
		
		$order = Order::where('txn_id', $params['Authority'])->first();
		if(!$order){
		  return [
		    'code' => 400, 
			'message' => __('order.not_found')
		  ];
		}
		
		$data = [
          'merchant_id' => $this->config['zarinpal_merchant'],
          'authority' => $params['Authority'],
          "amount" 	=> (int)$order->exchange_amount
        ];
		
		$verify = $this->_curlpost('verify', $data);
		if (
            isset($verify['data']) &&
            isset($verify['data']['code']) &&
            ($verify['data']['code'] == 100 || $verify['data']['code'] == 101)
        ) {
			return [
                'trade_no' => $order->trade_no ,
                'callback_no' => $params['Authority'],
                'code' => 200,
            ];
		} else {
			Log::error('Zarinpal could not process notification: ' . $params);
            return [
				'code' => 400, 
				'message' => __('order.process_failed')
			];
        }
    }
	
	/**
     * @return array<string, mixed> 
     */
    private function _curlpost($action, $params)
    {
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => 'https://api.zarinpal.com/pg/v4/payment/' . $action . '.json',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_SSL_VERIFYHOST => 0,
          CURLOPT_SSL_VERIFYPEER => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => json_encode($params),
          CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($params),
            'User-Agent: ZarinPal Rest Api v4'
          ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return json_decode($response, true, JSON_PRETTY_PRINT);
    }
}