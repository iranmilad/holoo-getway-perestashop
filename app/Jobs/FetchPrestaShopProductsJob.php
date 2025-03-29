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
    protected $user;
    protected $param;
    protected $category;
    protected $config;
    protected $holoo_cat;
    protected $wc_cat;
    protected $wcProducts;
    protected $apiUrl;
    protected $apiKey;

    public function __construct($user,$category,$config,$flag,$holoo_cat,$wc_cat,$wcProducts)
    {
        $this->apiUrl = env('API_URL'); // URL پایه API پرستاشاپ
        $this->apiKey = env('API_KEY'); // کلید API پرستاشاپ
        $this->user=$user;
        $this->config=$config;
        $this->category=$category;
        $this->holoo_cat=$holoo_cat;
        $this->wc_cat=$wc_cat;
        $this->wcProducts=$wcProducts;
    }

    public function handle()
    {
        if (!$this->apiUrl || !$this->apiKey) {
            Log::error("PrestaShop API credentials are missing.");
            return;
        }

        try {
            $products = $this->getProductsWithQuantities();
            $productsArray = $products instanceof \Illuminate\Support\Collection ? $products->toArray() : $products;
            $holooCodes = array_column($productsArray, 'upc');
            // remove null array member for $holooCodes
            $holooCodes=array_filter($holooCodes);

            $holooProducts = $this->getMultiProductHoloo($holooCodes);
            $holooProducts = $this->reMapHolooProduct($holooProducts);

            if (empty($holooProducts)) {
                Log::info('No products fetched from Holoo API.');
                return;
            }

            foreach ($products as $product) {

                $aCode = $product['upc'] ?? null;

                if ($aCode && isset($holooProducts[$aCode])) {
                    if($aCode!="0202002"){
                        Log::info("Product ID: {$product['id']} fetched from PrestaShop.");
                        continue;
                    }
                    //Log::info(json_encode($holooProducts[$aCode]));
                    ProcessPrestaShopProductJob::dispatch($product,(array) $holooProducts[$aCode]);
                }
            }
        } catch (Exception $e) {
            Log::error("PrestaShop API Fetch Error: " . $e->getMessage());
        }
    }

    public function GetMultiProductHoloo($holooCodes)
    {
        $curl = curl_init();
        $holooCodes=array_unique($holooCodes);
        $totalPage=ceil(count($holooCodes)/100);
        $totalProduct=[];

        for ($x = 1; $x <= $totalPage; $x+=1) {

            $GroupHolooCodes=implode(',', array_slice($holooCodes,($x-1)*100,100*$x));
            //log::info($GroupHolooCodes);
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://myholoo.ir/api/Article/GetProducts?a_Code=' . $GroupHolooCodes,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSL_VERIFYPEER =>false,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'serial: ' . $this->user->serial,
                    'database: ' . $this->user->holooDatabaseName,
                    'access_token: ' . $this->user->apiKey,
                    'Authorization: Bearer ' .$this->user->cloudToken,
                ),
            ));
            $response = curl_exec($curl);
            if($response and isset(json_decode($response, true)["data"]) and isset(json_decode($response, true)["data"]["product"])){
                $totalProduct=array_merge(json_decode($response, true)["data"]["product"] ??[],$totalProduct??[]);
            }

            $err = curl_errno($curl);
            $err_msg = curl_error($curl);
            $header = curl_getinfo($curl);

            log::info("start log cloud");
            // Log::info($header);
            // Log::info($err_msg);
            // Log::info($err);
        }
        //$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);


        //log::info("finish log cloud");
        //log::info("get http code ".$httpcode."  for get single product from cloud for holoo product id: ".$holoo_id);

        curl_close($curl);
        return $totalProduct;

    }
    public function getProductsWithQuantities()
    {
        // URL و API Key از فایل env خوانده می‌شوند
        $apiUrl = env('API_URL');
        $apiKey = env('API_KEY');

        try {
            // آدرس‌های API
            $endpoints = [
                'products' => $apiUrl . '/api/products?output_format=JSON&display=full',
                'stock' => $apiUrl . '/api/stock_availables?output_format=JSON&display=full',
            ];

            // تنظیمات header
            $headers = [
                'Authorization: Basic ' . base64_encode($apiKey . ':')
            ];

            // Multi-Handle
            $multiHandle = curl_multi_init();
            $curlHandles = [];

            // ایجاد هندل‌های cURL برای هر درخواست
            foreach ($endpoints as $key => $url) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // برای مواقعی که گواهی SSL معتبر نیست
                $curlHandles[$key] = $ch;
                curl_multi_add_handle($multiHandle, $ch);
            }

            // اجرای درخواست‌ها به صورت موازی
            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle); // برای بهبود کارایی
            } while ($running > 0);

            // جمع‌آوری نتایج
            $responses = [];
            foreach ($curlHandles as $key => $ch) {
                $response = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($httpCode >= 400) {
                    throw new \Exception("HTTP Error on $key endpoint: $httpCode");
                }

                $responses[$key] = json_decode($response, true);
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
            }

            // بستن Multi-Handle
            curl_multi_close($multiHandle);

            // پردازش نتایج
            $products = $responses['products']['products'] ?? [];
            $stockAvailables = $responses['stock']['stock_availables'] ?? [];

            // ایجاد یک آرایه برای نگاشت موجودی‌ها
            $stockMap = collect($stockAvailables)->keyBy('id_product');

            // ترکیب اطلاعات محصولات و موجودی‌ها
            $result = collect($products)->map(function ($product) use ($stockMap) {
                $stock = $stockMap->get($product['id']);

                return array_merge($product, [
                    'quantity' => $stock['quantity'] ?? 0, // مقدار quantity
                    'stock_id' => $stock['id'] ?? null,    // مقدار id مربوط به quantity
                    'id_product_attribute' => $stock['id_product_attribute'] ?? null, // مقدار id_product_attribute
                ]);
            });

            return $result;

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching data.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    public function reMapHolooProduct($holooProducts){
        $newHolooProducts = [];
        if(is_array($holooProducts)){
            foreach ($holooProducts as $key=>$HolooProd) {
                $HolooProd=(object) $HolooProd;
                if (isset($HolooProd->a_Code)){
                    $newHolooProducts[$HolooProd->a_Code]=$HolooProd;
                }
            }
        }
        return $newHolooProducts;
    }
}
