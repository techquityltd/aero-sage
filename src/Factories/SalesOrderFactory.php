<?php

namespace Techquity\Aero\Sage\Factories;

use Aero\Cart\Models\Order;
use Aero\Common\Models\Currency;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Traits\Macroable;

class SalesOrderFactory
{
    use Macroable;

    protected Order $order;

    protected bool $existing = false;

    protected string $orderReference;

    protected array $sales = [];

    public function __construct(Order $order)
    {
        $this->order = $order;

        // First lets establish if this is a new customer.
        if ($reference = $this->order->additional('sage_order_ref')) {
            $this->orderReference = $reference;
            $this->existing = true;
        }

        $this->createOrder();

        // Extend using a macro
        if ($this->hasMacro('extend')) {
            $this->extend();
        }

        $this->updateOrCreate();
    }

    protected function createOrder()
    {
        $this->setAccountReference();

        $this->sales['orderNumber'] = $this->order->additional('sage_order_ref');
        $this->sales['customerOrderNumber'] = $this->order->reference;
        $this->sales['orderDate'] = $this->order->ordered_at->format('d/m/Y');

        $this->setContactName($this->order->billingAddress->fullName);
        $this->setTelephone(($this->order->billingAddress->mobile ?? $this->order->billingAddress->phone) ?? '');

        $this->setAddress('address', [
            1 => (string) $this->order->billingAddress->line_1,
            2 => (string) $this->order->billingAddress->line_2,
            4 => (string) $this->order->billingAddress->city,
            5 => (string) $this->order->billingAddress->postcode
        ]);

        // Check for gift vouchers (as they don't have shipping)
        if ($this->order->shippingAddress) {
            $this->setAddress('delAddress', [
                1 => (string) $this->order->shippingAddress->line_1,
                2 => (string) $this->order->shippingAddress->line_2,
                4 => (string) $this->order->shippingAddress->city,
                5 => (string) $this->order->shippingAddress->postcode
            ]);

            $this->sales['carrNet'] = $this->order->shipping / 100;
            $this->sales['carrTax'] = $this->order->shipping_tax / 100;
        } else {
            $this->setAddress('delAddress', [
                1 => (string) $this->order->billingAddress->line_1,
                2 => (string) $this->order->billingAddress->line_2,
                4 => (string) $this->order->billingAddress->city,
                5 => (string) $this->order->billingAddress->postcode
            ]);
        }

        $this->sales['netValueDiscountAmount'] = $this->order->discount / 100;

        $this->sales['carrNomCode'] = "4905";
        $this->sales['carrTaxCode'] = 1;

        $this->sales['notes1'] = 'New Order';

        $this->setCurrencyCode($this->order->currency);

        $this->setInvoiceItems();

        return $this;
    }

    /**
     * Get the account reference for the sage customer.
     */
    protected function setAccountReference()
    {
        if ($this->order->customer && $this->order->customer->sage_account_id) {
            return $this->sales['customerAccountRef'] = $this->order->customer->sage_account_id;
        }

        if ($reference = $this->order->additional('sage_customer_ref')) {
            return $this->sales['customerAccountRef'] = $reference;
        }

        // THROW SOMETHING
    }

    /**
     * Validate and set the contacts name.
     */
    protected function setContactName(string $name): void
    {
        if (strlen($name) <= 30) {
            $this->sales['contactName'] = $name;
        }
    }

    /**
     * Validate and set the customers telephone.
     */
    protected function setTelephone(string $telephone): void
    {
        if (strlen($telephone) >= 30) {
            $this->sales['customerTelephoneNumber'] = $telephone;
        }
    }

    /**
     * Validate and set the customers specific address line.
     */
    protected function setAddress(string $type, array $address): void
    {
        foreach ($address as $line => $value) {
            if (strlen($value) && strlen($value) <= 60) {
                $this->sales[$type . $line] = $value;
            }
        }
    }

    /**
     * Set the currency id for the customer.
     */
    protected function setCurrencyCode(Currency $currency): void
    {
        if (array_key_exists($currency->code, setting('sage_50.currencies'))) {
            $this->sales['currency'] = setting('sage_50.currencies')[$currency->code];
        }
    }

    /**
     * Set the invoice items from the order items.
     */
    protected function setInvoiceItems()
    {
        $this->sales['invoiceItems'] = $this->order->items->map(function ($item) {

            // Extend using a macro
            if ($this->hasMacro('extendItem')) {
                $extend = $this->extendItem($item);
            }

            return array_merge($extend ?? [], [
                'stockCode' => $item->sku,
                'description' => $item->name,
                'quantity' => $item->quantity,
                'unitPrice' => ($item->price + $item->tax) / 100,
                'taxRate' => round((($item->tax / $item->price) * 100), 2),
                'taxCode' => 1,
                'nominal' => 4001,
                'discount' => round((($item->discount / $item->price) * 100), 2),
                'discountAmount' => ($item->discount + $item->discount_tax) / 100,
                'netAmount' => $item->price / 100,
            ]);
        })->toArray();
    }

    /**
     * Update or create the customer in sage.
     */
    public function updateOrCreate()
    {
        $client = new Client([
            'base_uri' => sprintf('https://nbu.hypersage.co.uk:%d/api/', setting('sage_50.port')),
            'http_errors' => false,
            'headers' => [
                'AuthToken' => setting('sage_50.auth_token'),
            ],
        ]);

        $response = $client->request($this->existing ? 'patch' : 'post', 'salesOrder', [
            'json' => $this->sales
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        if ($response['success']) {
            $this->order->additional('sage_order_ref', $response['response']);
        } else {
            Log::error($response['message'], [
                'integration' => 'Sage 50',
                'salesOrder' => $this->sales
            ]);
        }
    }
}
