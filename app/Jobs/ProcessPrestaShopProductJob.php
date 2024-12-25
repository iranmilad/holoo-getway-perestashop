<?php

namespace App\Jobs;

use Exception;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;


class ProcessPrestaShopProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $productData;
    protected $holooData;
    protected $apiUrl;

    public function __construct(array $productData, array $holooData)
    {
        $this->productData = $productData;
        $this->holooData = $holooData;
        $this->apiUrl = config('prestashop.api_url');
    }

    public function handle()
    {
        try {
            $needsUpdate = false;

            if ($this->productData['price'] != $this->holooData['price']) {
                $this->productData['price'] = $this->holooData['price'];
                $needsUpdate = true;
            }

            if ($this->productData['quantity'] != $this->holooData['quantity']) {
                $this->productData['quantity'] = $this->holooData['quantity'];
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $updateResponse = Http::withBasicAuth(config('prestashop.api_key'), '')
                    ->put("{$this->apiUrl}/products/{$this->productData['id']}", [
                        'product' => [
                            'price' => $this->productData['price'],
                            'quantity' => $this->productData['quantity']
                        ]
                    ]);

                if ($updateResponse->failed()) {
                    throw new Exception("Failed to update product ID: {$this->productData['id']} on PrestaShop.");
                }

                Log::info("Product ID: {$this->productData['id']} updated successfully on PrestaShop.");
            } else {
                Log::info("Product ID: {$this->productData['id']} is already up to date.");
            }
        } catch (Exception $e) {
            Log::error("Error processing product ID {$this->productData['id']}: " . $e->getMessage());
        }
    }
}
