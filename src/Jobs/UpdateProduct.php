<?php

namespace Techquity\Aero\Sage\Jobs;

use Aero\Catalog\Events\ProductUpdated;
use Aero\Catalog\Models\Variant;
use Aero\Common\Models\Country;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $modifiedDate;

    public function __construct()
    {
        $this->modifiedDate = now()->subHours(2);
    }

    public function handle(): void
    {
        $client = new Client([
            'base_uri' => sprintf('https://%s:%d/api/', setting('sage_50.api_url'), setting('sage_50.port')),
            'http_errors' => false,
            'headers' => [
                'AuthToken' => setting('sage_50.auth_token'),
            ],
        ]);

        $response = $client->post('searchProduct', [
            'json' => [
                [
                    'field' => 'RECORD_MODIFY_DATE',
                    'type'  => 'gte',
                    'value' => $this->modifiedDate->format('d/m/Y')
                ]
            ]
        ]);

        $contents = $response->getBody()->getContents();
        
        if (setting('sage_50.superfluous_logging')) {
            Log::debug('Product Update Response', [
                'response' => $contents,
            ]);
        }

        $response = json_decode($contents);

        if ($response->success) {
            collect($response->results)->each(function ($product) {
                if ($variant = Variant::with(['product', 'prices'])->firstWhere('sku', $product->stockCode)) {

                    $price = $variant->basePrice()->first();

                    if (setting('sage_50.product_stock')) {
                        $variant->stock_level = (int) $product->qtyInStock;
                    }

                    if (setting('sage_50.product_pricing')) {
                        $price = $variant->basePrice()->first();
                        $price->value = $product->salesPrice * 100;
                    }

                    if (setting('sage_50.product_detailed')) {
                        $variant->cost_value = $product->lastCostPrice * 100;
                        $variant->barcode = $product->barcode;
                        $variant->hs_code = $product->commodityCode;

                        $variant->weight_unit = 'g';
                        $variant->Weight = $product->unitWeight;

                        if ($country = Country::firstWhere('code', $product->countryCodeOfOrigin)) {
                            $variant->originCountry()->associate($country);
                        }
                    }

                    if ($variant->isDirty() || $price->isDirty()) {

                        if (setting('sage_50.superfluous_logging')) {
                            Log::debug('Product Updates ' . $variant->id, [
                                'integration' => 'sage 50',
                                'variant' => $variant->getDirty(),
                                'prices' => $price->getDirty()
                            ]);
                        }

                        $variant->save();
                        $price->save();

                        event(new ProductUpdated($variant->product));
                    } else {
                        if (setting('sage_50.superfluous_logging')) {
                            Log::debug('Product Updates ' . $variant->id, [
                                'integration' => 'sage 50',
                                'variant' => 'no changes',
                                'prices' => 'no changes'
                            ]);
                        }
                    }
                }
            });
        }
    }
}
