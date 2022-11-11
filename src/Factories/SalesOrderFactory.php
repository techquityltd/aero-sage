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
        $this->setDeliveryName($this->order->shippingAddress->fullName);
        $this->setTelephone(($this->order->billingAddress->mobile ?? $this->order->billingAddress->phone) ?? '');

        $this->setAddress('address', [
            1 => (string) $this->order->billingAddress->line_1, // Street 1
            2 => (string) $this->order->billingAddress->line_2, // Street 2
            3 => (string) $this->order->billingAddress->city, // Town
            //4 => // County
            5 => (string) $this->order->billingAddress->postcode // Postcode
        ]);

        // Check for gift vouchers (as they don't have shipping)
        if ($this->order->shippingAddress) {
            $this->setAddress('delAddress', [
                1 => (string) $this->order->shippingAddress->line_1, // Street 1
                2 => (string) $this->order->shippingAddress->line_2, // Street 2
                3 => (string) $this->order->shippingAddress->city, // Town
                //4 => // County
                5 => (string) $this->order->shippingAddress->postcode // Postcode
            ]);

            $this->sales['carrNet'] = round($this->order->shipping / 100, 2);
            $this->sales['carrTax'] = round($this->order->shipping_tax / 100, 2);
        } else {
            $this->setAddress('delAddress', [
                1 => (string) $this->order->billingAddress->line_1, // Street 1
                2 => (string) $this->order->billingAddress->line_2, // Street 2
                3 => (string) $this->order->billingAddress->city, // Town
                //4 => // County
                5 => (string) $this->order->billingAddress->postcode // Postcode
            ]);
        }

        //this->sales['netValueDiscountAmount'] = round($this->order->discount / 100, 2);

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
     * Validate and set the contacts name.
     */
    protected function setDeliveryName(string $name): void
    {
        if (strlen($name) <= 30) {
            $this->sales['delName'] = $name;
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

            $options = collect($item->options)
                ->filter(fn ($item) => isset($item['name']) && isset($item['value']))
                ->map(fn ($item) => "{$item['name']}: {$item['value']}")
                ->implode(', ');

            $options = empty($options) ? '' : " ({$options})";

            return array_merge($extend ?? [], [
                'stockCode' => $item->sku,
                'description' => $item->name . $options,
                'quantity' => $item->quantity,
                'unitPrice' => $item->priceRounded / 100,
                'taxRate' => ($item->subtotalTaxRounded > 0) ? round((($item->tax / $item->price) * 100), 2) : 0,
                'taxCode' => ($item->subtotalTaxRounded > 0) ? 1 : 0,
                'nominal' => 4001,
                'discount' => $item->discountRounded / 100,
                'discountAmount' => 0,
                'netAmount' => round(($item->price * $item->quantity) - $item->discount) / 100,
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

            if (setting('sage_50.debug_mode')) {
                Log::debug('Sage Customer', [
                    'integration' => 'sage 50',
                    'request' => $this->sales,
                    'response' => $response
                ]);
            }
        } else {
            Log::error($response['message'], [
                'integration' => 'Sage 50',
                'salesOrder' => $this->sales,
                'response' => $response
            ]);
        }
    }
}
