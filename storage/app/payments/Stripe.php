<?php

namespace Payments;

use App\Services\CurrencyService;
use InvalidArgumentException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Charge;
use Stripe\StripeClient;
use UnexpectedValueException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles Stripe card payment processing and notifications.
 */
final class Stripe
{
    /**
     * The CurrencyService client instance.
     *
     * @var CurrencyService
     */

    protected CurrencyService $currencyService;

    /**
     * Create a new StripeCard instance.
     *
     * @param array<string, mixed> $config Configuration array for Stripe.
     *
     * Expected config keys:
     * - stripe_sk_live: Stripe secret key
     * - stripe_pk_live: Stripe publishable key
     * - stripe_webhook_key: Stripe webhook secret
     * - stripe_method: Payment method (e.g., 'card', 'alipay', 'wechatpay')
     * - currency: Currency code (e.g., 'USD')
     * - currency_decimals: Number of decimal places for currency
     * - statement_descriptor: Optional statement descriptor (defaults to app name)
     */
    public function __construct(protected array $config)
    {
        $this->currencyService = new CurrencyService();
    }
	
	private function stripeClient(): StripeClient
    {
        return new StripeClient([
            'api_key' => $this->config['stripe_sk_live'],
            'stripe_version' => '2024-09-30.acacia',
        ]);
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
                'default' => 'Stripe',
                'disabled' => true,
            ],
            'stripe_method' => [
                'label' => 'Method',
                'type' => 'array',
                'options' => [
					[ 'text' => 'Card', 'value' => 'card' ], 
					[ 'text' => 'Alipay', 'value' => 'alipay' ], 
					['text' => 'WechatPay', 'value' => 'wechat_pay' ]
				],
				'default' => 'card',
                'disabled' => false,
            ],
            'stripe_sk_live' => [
                'label' => 'Secret Key',
                'type' => 'text',
                'default' => '',
				'placeholder' => 'sk_live_...',
                'disabled' => false,
            ],
            'stripe_pk_live' => [
                'label' => 'Publishable Key',
                'type' => 'text',
                'default' => '',
				'placeholder' => 'pk_live_...',
                'disabled' => false,
            ],
            'stripe_webhook_key' => [
                'label' => 'Webhook Secret',
                'type' => 'text',
				'placeholder' => 'whsec_...',
                'default' => '',
                'disabled' => false,
            ],
            'statement_descriptor' => [
                'label' => 'Descriptor',
                'type' => 'text',
                'default' => config('xmplus.app_name', ''),
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
     * Create a Stripe payment intent for an order.
     *
     * @param array<string, mixed> $order Order details.
     * @return array<string, mixed> Payment intent details.
     *
     * Expected order keys:
     * - total_amount: The order amount (numeric)
     * - pay_id: Unique payment identifier
     * - return_url: URL to return to after payment
     * - cancel_url: URL to return to after cancellation
     */
    public function pay(array $order): array
    {
        $requiredKeys = ['total_amount', 'pay_id', 'return_url', 'cancel_url'];
        foreach ($requiredKeys as $key) {
            if (!isset($order[$key])) {
                throw new InvalidArgumentException(__("order.missing_key").$key);
            }
        }

        if (!is_numeric($order['total_amount'])) {
            throw new InvalidArgumentException(__("order.Invalid_amount"));
        }

        $rates = $this->currencyService->fetchRates();
        $defaultCurrency = config('xmplus.default_currency_code', 'USD'); // Fallback default currency
        if (!isset($rates[$this->config['currency']], $rates[$defaultCurrency])) {
            throw new InvalidArgumentException(__('order.invalid_rate'));
        }

        $value = $rates[$this->config['currency']] / $rates[$defaultCurrency];
        $total = number_format((float) $order['total_amount'] * $value, $this->config['currency_decimals'], '.', '');
		$stripe_method = $this->config['stripe_method'];
		
        try {
            $paymentIntent = $this->stripeClient()->paymentIntents->create([
                'payment_method_types' => [$this->config['stripe_method']],
                'amount' => (int) ($total * 100),
                'currency' => $this->config['currency'],
                'statement_descriptor' => $this->config['statement_descriptor'] ?? config('xmplus.app_name', ''),
                'metadata' => [
                    'out_trade_no' => $order['pay_id'],
                ],
            ]);
			
			if(in_array($this->config['stripe_method'], $paymentIntent->payment_method_types)){
				$payment_method_type =  $this->config['stripe_method'];
			}else {
				$payment_method_type =  "";
			}
			
            return [
			    'paymentIntent' => $paymentIntent,
                'client_secret' => $paymentIntent->client_secret,
                'stripe_pk_live' => $this->config['stripe_pk_live'],
                'notify_url' => $this->config['notify_url'] ?? '',
                'return_url' => $order['return_url'],
                'cancel_url' => $order['cancel_url'],
                'total_amount' => $paymentIntent->amount ? ($paymentIntent->amount / 100) : $total,
                'currency' => strtoupper($paymentIntent->currency) ?? $this->config['currency'] ?? $defaultCurrency,
				'external' => false,
				'payment_method_type' => $payment_method_type,
				'link' => $paymentIntent->id
            ];
        } catch (\Throwable $e) {
			Log::error('Stripe Payment Error: ' . $e->getMessage(), [
                'order' => $order,
                'config_id' => $this->config['id'] ?? null
            ]);
            throw new \Exception(__('order.stripe.create_failed',['remarks' => $this->config['remarks']]) . $e->getMessage());
        }
    }

    /**
     * Handle Stripe webhook notifications.
     *
     * @param Request $request Webhook payload (JSON string or array).
     * @return array<string, mixed> Notification result with trade_no, callback_no, and code.
     * @throws InvalidArgumentException If webhook payload is invalid or verification fails.
     */
	public function notify($request): array
	{
		$params = $request->getContent();
		// Normalize params to array
		$payload = is_string($params) ? json_decode($params, true) : $params;
		if (!is_array($payload)) {
			throw new \Exception(__('order.stripe.invalid_payload',['remarks' => $this->config['remarks']]));
		}
		
		$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? $request->header('HTTP_STRIPE_SIGNATURE') ?? $request->header('stripe-signature');
		if (empty($signature)) {
			Log::error('Stripe webhook: Missing signature header');
            return ['status' => 'error', 'message' => 'StripeCard: '.__('order.stripe.missing_header',['remarks' => $this->config['remarks']])];
        }
		
		$event = null;
		
		try {
			$event = \Stripe\Webhook::constructEvent(
				$params,
				$signature,
				$this->config['stripe_webhook_key']
			);
			
			if ($event->type === 'payment_intent.succeeded' && isset($event->data->object) && $event->data->object instanceof PaymentIntent) {
				$paymentIntent = $event->data->object;
				if (isset($paymentIntent->metadata['out_trade_no'])) {
					return [
						'trade_no' => $paymentIntent->metadata['out_trade_no'],
						'callback_no' => $paymentIntent->id,
						'code' => 200,
					];
				}
			} /*elseif ($event->type === 'charge.succeeded' && isset($event->data->object) && $event->data->object instanceof Charge) {
				$charge = $event->data->object;
				$metadata = $charge->metadata ?? ($charge->source->metadata ?? null);
				if (isset($metadata['out_trade_no'])) {
					return [
						'trade_no' => $metadata['out_trade_no'],
						'callback_no' => $charge->id,
						'code' => 200,
					];
				}
			} */elseif ($event->type === 'payment_intent.payment_failed' && isset($event->data->object) && $event->data->object instanceof PaymentIntent) {
				return [
					'code' => 400,
					'message' => __('order.stripe.process_failed',['remarks' => $this->config['remarks']]) . $event->data->object->id,
				];
			} elseif ($event->type === 'payment_intent.created' && isset($event->data->object) && $event->data->object instanceof PaymentIntent) {
				return [
					'code' => 200,
					'status' => 'pending',
				];
			}
		} catch (SignatureVerificationException|UnexpectedValueException $e) {
			Log::error('Stripe Signature Verification failed: ' . $e->getMessage());
			return ['code' => 400, 'message' => 'StripeCard: ' . $e->getMessage()];
		} catch (\Throwable $e) {
            Log::error('Stripe webhook processing error: ' . $e->getMessage(), ['params' => $params]);
            return ['code' => 400, 'message' => __('order.stripe.notify_failed',['remarks' => $this->config['remarks']])];
        }
		
        return ['code' => 200, 'status' => 'ok'];
	}
}