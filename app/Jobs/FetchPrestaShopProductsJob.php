<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class FetchPrestaShopProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $apiUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('prestashop.api_url');
        $this->apiKey = config('prestashop.api_key');
    }

    public function handle()
    {
        if (!$this->apiUrl || !$this->apiKey) {
            Log::error("PrestaShop API credentials are missing.");
            return;
        }

        try {
            $response = Http::withBasicAuth($this->apiKey, '')
                ->get("{$this->apiUrl}/products", [
                    'display' => '[id,price,quantity,reference]',
                    'limit' => 1000
                ]);

            if ($response->failed()) {
                throw new Exception("Failed to fetch products: " . $response->body());
            }

            $products = $response->json()['products'] ?? [];
            $holooCodes = array_column($products, 'reference');
            $holooProducts = $this->getMultiProductHoloo($holooCodes);

            if (empty($holooProducts)) {
                Log::info('No products fetched from Holoo API.');
                return;
            }

            foreach ($products as $product) {
                $aCode = $product['reference'] ?? null;

                if ($aCode && isset($holooProducts[$aCode])) {
                    ProcessPrestaShopProductJob::dispatch($product, $holooProducts[$aCode]);
                }
            }
        } catch (Exception $e) {
            Log::error("PrestaShop API Fetch Error: " . $e->getMessage());
        }
    }

    private function getMultiProductHoloo($holooCodes)
    {
        $curl = curl_init();
        $holooCodes = array_unique($holooCodes);
        $totalPage = ceil(count($holooCodes) / 100);
        $totalProduct = [];

        for ($x = 1; $x <= $totalPage; $x++) {
            $groupHolooCodes = implode(',', array_slice($holooCodes, ($x - 1) * 100, 100));

            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://myholoo.ir/api/Article/GetProducts?a_Code=' . $groupHolooCodes,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => [
                    'serial: ' . config('holoo.serial'),
                    'database: ' . config('holoo.database'),
                    'access_token: ' . config('holoo.api_key'),
                    'Authorization: Bearer ' . config('holoo.cloud_token'),
                ],
            ]);

            $response = curl_exec($curl);
            if ($response && isset(json_decode($response, true)["data"]["product"])) {
                $totalProduct = array_merge($totalProduct, json_decode($response, true)["data"]["product"]);
            }
        }

        curl_close($curl);

        // Index products by a_code for faster lookups
        return collect($totalProduct)->keyBy('a_code')->toArray();
    }
}
