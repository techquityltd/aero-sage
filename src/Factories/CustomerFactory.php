<?php

namespace Techquity\Aero\Sage\Factories;

use Aero\Cart\Models\Order;
use Aero\Common\Models\Currency;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Traits\Macroable;

class CustomerFactory
{
    use Macroable;

    protected Order $order;

    protected bool $existing = false;

    protected string $accountReference;

    protected array $customer = [];

    public function __construct(Order $order)
    {
        $this->order = $order;

        // First lets establish if this is a new customer.
        if ($order->customer && $order->customer->sage_account_id) {
            $this->accountReference = $order->customer->sage_account_id;
            $this->existing = true;
        } elseif ($reference = $this->order->additional('sage_customer_ref')) {
            $this->accountReference = $reference;
            $this->existing = true;
        }

        $this->createCustomer();

        // Extend using a macro
        if ($this->hasMacro('extend')) {
            $this->extend();
        }

        $this->updateOrCreate();
    }

    protected function createCustomer()
    {
        $this->setAccountReference();

        $this->setName($this->order->billingAddress->company, $this->order->billingAddress->fullName);
        $this->setContactName($this->order->billingAddress->fullName);
        $this->setTelephone(($this->order->billingAddress->mobile ?? $this->order->billingAddress->phone) ?? '');

        $this->setAddress('address', [
            1 => (string) $this->order->billingAddress->line_1,
            2 => (string) $this->order->billingAddress->line_2,
            4 => (string) $this->order->billingAddress->city,
            5 => (string) $this->order->billingAddress->postcode
        ]);

        if ($this->order->shippingAddress) {
            $this->setAddress('deliveryAddress', [
                1 => (string) $this->order->shippingAddress->line_1,
                2 => (string) $this->order->shippingAddress->line_2,
                4 => (string) $this->order->shippingAddress->city,
                5 => (string) $this->order->shippingAddress->postcode
            ]);
        }

        $this->customer['countryCode'] = $this->order->billingAddress->country_code;

        $this->setCurrencyCode($this->order->currency);
        $this->setEmail($this->order->email);

        $this->customer['defTaxCode'] = setting('sage_50.def_tax_code');
        $this->customer['defNomCode'] = setting('sage_50.def_nom_code');

        $this->customer['sendInvoicesElectronically'] = false;
        $this->customer['sendLettersElectronically'] = false;

        return $this;
    }

    /**
     * Get the account reference or generate a new one.
     */
    protected function setAccountReference(): void
    {
        // Allow the option to override default reference generation
        if (!$this->existing && $this->hasMacro('generateAccountReference')) {
            $this->customer['accountRef'] = $this->generateAccountReference();
        } elseif ($this->existing) {
            $this->customer['accountRef'] = $this->accountReference;
        }
    }

    /**
     * Validate and set the customers/companies name.
     */
    protected function setName(?string $company, ?string $name): void
    {
        $spacelessCompany = preg_replace("/[^A-Za-z]/", "", $company);
        $spacelessName = preg_replace("/[^A-Za-z]/", "", $name);

        if (strlen($spacelessCompany) >= 4 && strlen($spacelessCompany) <= 60) {
            $this->customer['name'] = $company;
        } elseif (strlen($spacelessName) >= 4 && strlen($spacelessName) <= 60) {
            $this->customer['name'] = $name;
        } else {
            Log::error("Sage Issue: {$this->order->reference} - customer name invalid for sage", [
                'integration' => 'sage 50',
            ]);
        }
    }

    /**
     * Validate and set the contacts name.
     */
    protected function setContactName(string $name): void
    {
        if (strlen($name) <= 30) {
            $this->customer['contactName'] = $name;
        }
    }

    /**
     * Validate and set the customers telephone.
     */
    protected function setTelephone(string $telephone): void
    {
        if (strlen($telephone) >= 30) {
            $this->customer['telephone'] = $telephone;
        }
    }

    /**
     * Validate and set the customers specific address line.
     */
    protected function setAddress(string $type, array $address): void
    {
        foreach ($address as $line => $value) {
            if (strlen($value) && strlen($value) <= 60) {
                $this->customer[$type . $line] = $value;
            }
        }
    }

    /**
     * Set the currency id for the customer.
     */
    protected function setCurrencyCode(Currency $currency): void
    {
        if (array_key_exists($currency->code, setting('sage_50.currencies'))) {
            $this->customer['currency'] = setting('sage_50.currencies')[$currency->code];
        }
    }

    /**
     * Validate and set the customers email.
     */
    protected function setEmail(string $email): void
    {
        if (strlen($email) <= 255) {
            $this->customer['email'] = $email;
        }
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

        $response = $client->request($this->existing ? 'patch' : 'post', 'customer', [
            'json' => $this->customer
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        if (isset($response['success']) && $response['success']) {
            $this->accountReference = $response['response'];

            if ($customer = $this->order->customer) {
                $customer->sage_account_id = $this->accountReference;
                $customer->save();
            } else {
                $this->order->additional('sage_customer_ref', $this->accountReference);
            }

            if (setting('sage_50.debug_mode')) {
                Log::debug('Sage Customer ', [
                    'integration' => 'sage 50',
                    'request' => $this->customer,
                    'response' => $response
                ]);
            }
        } else {
            $message = isset($response['Message']) ? $response['Message'] : ($response['message'] ?? 'Unknown');

            Log::error('Sage Response: ' . $message ?? 'Issue importing customer', [
                'integration' => 'sage 50',
                'data' => $this->customer
            ]);
        }
    }
}
