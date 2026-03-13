<?php

namespace Payments;

use Illuminate\Http\Request;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Log;
use Srmklive\PayPal\Services\PayPal as PaypalClient;

final class Paypal
{
    /**
     * The Paypal client instance.
     *
     * @var paypalClient
     */
    protected PaypalClient $paypalClient;

    protected CurrencyService $currencyService;

    /**
     * Create a new Paypal instance.
     *
     * @param array<string, mixed> $config Configuration array for Paypal.
     *
     * Expected config keys:
     * - paypal_mode: live or sandbox
     * - paypal_client: paypal ckient key
     * - paypal_secret: paypal secret
	 * - paypal_webhook_id: webhook id
     * - currency: Currency code (e.g., 'USD')
     * - currency_decimals: Number of decimal places for currency
     */
	
	public function __construct(protected array $config)
    {
		$this->currencyService = new CurrencyService();
    }
	
    private function paypalClient(): PaypalClient
    {
		$requiredKeys = ['paypal_mode', 'paypal_client', 'paypal_secret', 'currency', 'currency_decimals','remarks'];
        foreach ($requiredKeys as $key) {
            if (!isset($this->config[$key])) {
                throw new \Exception(__("order.payment.missing_key").$key);
            }
        }
		
        $this->paypalClient = new PaypalClient([
            'mode' => ($this->config['paypal_mode'] ?? 'sandbox'),
            'sandbox' => [
                'client_id' => $this->config['paypal_client'],
                'client_secret' => $this->config['paypal_secret'],
                'app_id' => '',
            ],
            'live' => [
                'client_id' => $this->config['paypal_client'],
                'client_secret' => $this->config['paypal_secret'],
                'app_id' => '',
            ],
            'payment_action' => 'Sale',
            'currency' => $this->config['currency'],
            'notify_url' => $this->config['notify_url'],
            'locale' => 'en_US',
            'validate_ssl' => false,
        ]);
        
		$this->paypalClient->setRequestHeader('Prefer', 'return=representation');
		$this->paypalClient->getAccessToken();
		
		return $this->paypalClient; 
    }
	
    public function getData(): array
    {
        try {
            return [
                'id' => $this->config['paypal_client'],
                'uuid' => $this->config['uuid']
            ];
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
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
                'default' => 'Paypal',
                'disabled' => true,
            ],
            'paypal_mode' => [
                'label' => 'Mode',
                'type' => 'array',
				'options' => [
                    ['text' => 'Live', 'value' => 'live'],
                    ['text' => 'Sandbox', 'value' => 'sandbox'],
                ],
                'default' => 'sandbox',
                'disabled' => false,
            ],
            'paypal_client' => [
                'label' => 'Client Id',
                'type' => 'text',
                'default' => '',
                'disabled' => false,
            ],
            'paypal_secret' => [
                'label' => 'Client Secret',
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
     * Create a Paypal payment intent for an order.
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
        $requiredKeys = ['total_amount', 'pay_id'];
        foreach ($requiredKeys as $key) {
            if (!isset($order[$key])) {
                throw new \Exception(__("order.missing_key").$key);
            }
        }

        $rates = $this->currencyService->fetchRates();
        $defaultCurrency = config('xmplus.default_currency_code', 'USD'); // Fallback default currency
        if (!isset($rates[$this->config['currency']], $rates[$defaultCurrency])) {
            throw new \InvalidArgumentException(__('order.invalid_rate'));
        }

        $value = $rates[$this->config['currency']] / $rates[$defaultCurrency];
        $total = number_format((float) $order['total_amount'] * $value, $this->config['currency_decimals'], '.', '');

        try {
            $createOrder = $this->paypalClient()->createOrder([
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'amount' => [
                            'currency_code' => $this->config['currency'],
                            'value' => $total,
							'breakdown' => [
                                'item_total' => [
                                    'currency_code' => $this->config['currency'],
                                    'value' => $total,
                                ]
                            ]
                        ],
						"description" =>  $order['description'],
						"items" => [
							[
								"name" => config('xmplus.app_name')." Data Purchase",
								"description" =>  $order['description'],
								"unit_amount" => [ 
									"currency_code" => $this->config['currency'],
									"value" => $total,
								],
								"quantity" => "1",
								"sku" => $order['pay_id'],
							]
						],
                        'reference_id' => $order['pay_id'],
					    "invoice_id"  => $order['pay_id'],
                    ],
                ],
				'application_context' => [
                    'cancel_url' => $order['cancel_url'],
                    'return_url' => $this->config['notify_url'],
                ],
				"payment_source" => [
					"paypal" => [
					  "experience_context" => [
						"shipping_preference" => "NO_SHIPPING"
					  ]
					]
				],
            ]);

            if (isset($createOrder['error'])) {
				$errorMessage = $createOrder['error']['message'] ?? 
                               ($createOrder['error']['details'][0]['description'] ?? 'Unknown error');
                abort(422, $errorMessage);
            }
			

            $links = $createOrder['links'];
            $approveLink = array_filter($links, fn($link) => $link['rel'] === 'approve');
            $payerActionLink = array_filter($links, fn($link) => $link['rel'] === 'payer-action');
            
            if (!empty($approveLink)) {
                $link = reset($approveLink)['href'];
            } elseif (!empty($payerActionLink)) {
                $link = reset($payerActionLink)['href'];
            } else {
                abort(422, 'Neither approve nor payer-action link found in PayPal response');
            }

            return [
                'data' => $link,
                'link' => $link,
				'txn_id' => $createOrder['id'],
				'notify_url' => $this->config['notify_url'] ?? "",
				'return_url' => $order['return_url'],
				'cancel_url' => $order['cancel_url'],
                'total_amount' => $total,
				'external' => false,
            ];
        } catch (\Throwable $e) {
			Log::error('Paypal: ',[
			    'message' => $e->getMessage(),
				'line' => $e->getLine(),
				'file' => $e->getFile(),
			]);
			
            abort(422, $e->getMessage());
        }
    }

    /**
     * Handle Paypal notifications.
     *
     * @param Request $request Paypal payload (JSON string or array).
     * @return array<string, mixed> Notification result with trade_no, callback_no, and code.
     */
    public function notify(Request $request)
    {
		$webhookEvent = $request->input();

		try{
			$orderId = $webhookEvent['order_id'] ?? null;
                
            if (!$orderId) {
                Log::error('PayPal notify: No order ID found', ['webhook_event' => $webhookEvent]);
                return [
                    'code' => 400,
                    'message' => __('order.paypal.notify_failed', ['remarks' => $this->config['remarks']]) . ': No order ID',
                ];
            }
                
            $result = $this->paypalClient()->capturePaymentOrder($orderId);

            if (isset($result['status']) && $result['status'] === 'COMPLETED') {
                return [
                    'trade_no' => $result['purchase_units'][0]['invoice_id'] ?? $result['purchase_units'][0]['reference_id'] ?? null,
                    'callback_no' => $orderId,
                    'code' => 200
                ];
            }
		} catch (\Throwable $e) {
			Log::error('PayPal notification exception', [
                'message' => $e->getMessage(),
				'line' => $e->getLine(),
                'webhook_event' => $webhookEvent
            ]);
			
			return [
				'code' => 400,
				'message' => __('order.paypal.notify_failed',['remarks' => $this->config['remarks']]) . $e->getMessage(),
			];
        }
		
		return ['code' => 200, 'status' => 'ok'];
    }
}
