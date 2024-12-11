<?php

namespace App\Jobs;



use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;

use App\Jobs\UpdateProductFindStep2;
use App\Jobs\UpdateProductFindStep2All;


use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;



class UpdateProductFind implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $param;
    protected $category;
    protected $config;
    public $flag;
    public $failOnTimeout = true;
    protected $product;
    protected $capacity_per_page=100;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user,$category,$config,$flag)
    {
        Log::info(' queue update product find start');
        $this->user=$user;
        $this->config=$config;
        $this->category=$category;
        $this->flag=$flag;

    }

    /**
     * Execute the job.
     *
     * @return void
     */



    public function handle()
    {
        $user_id=$this->user->id;
        Log::info("update for user id $user_id");
        //Log::info("token is ");
        //$token=$this->getNewToken();
        //if ($token==null) return;
        log::info ($this->user->siteUrl);

        $queue_delicate =$this->checkUserInString($this->user->queue_server);
        $this->capacity_per_page = ($queue_delicate) ? 10 : 100;

        if ($this->user->user_traffic=="heavy"){
            $page=1;
            // if($page<=16){
            //     $page=16;
            //     log::info("go to page ".$page." test log active");
            // }
            log::info("start page ".$page);
            do {
                // if($page>=18){
                //     log::info("break to next page test log active is 18");
                //     break;
                // }

                $wcProducts = $this->fetchAllWCProdsPaging(false,null,$page);
                if($wcProducts){
                    UpdateProductFindStep2All::dispatch((object)["queue_server"=>$this->user->queue_server,"id"=>$this->user->id,"siteUrl"=>$this->user->siteUrl,"serial"=>$this->user->serial,"apiKey"=>$this->user->apiKey,"holooDatabaseName"=>$this->user->holooDatabaseName,"consumerKey"=>$this->user->consumerKey,"consumerSecret"=>$this->user->consumerSecret,"cloudTokenExDate"=>$this->user->cloudTokenExDate,"cloudToken"=>$this->user->cloudToken, "holo_unit"=>$this->user->holo_unit, "plugin_unit"=>$this->user->plugin_unit,"user_traffic"=>$this->user->user_traffic,"poshak"=>$this->user->poshak],$this->config->product_cat,$this->config,1,[],[],$wcProducts)->onConnection($this->user->queue_server)->onQueue("default");
                }
                $page=$page+1;
                log::info("go to next page ".$page." for user id ".$user_id);
            }
            while ( count($wcProducts) == $this->capacity_per_page and $page<5000);
            log::info("finish page at page ".$page." for user id ".$user_id);

        }
        else{
            foreach ($this->category as $holoo_cat=>$wc_cat) {
                if ($wc_cat=="") {
                    continue;
                }
                if(is_array($wc_cat)){
                    foreach ($wc_cat as $wc_cat_id) {
                        UpdateProductFindStep2::dispatch((object)["queue_server"=>$this->user->queue_server,"id"=>$this->user->id,"siteUrl"=>$this->user->siteUrl,"serial"=>$this->user->serial,"apiKey"=>$this->user->apiKey,"holooDatabaseName"=>$this->user->holooDatabaseName,"consumerKey"=>$this->user->consumerKey,"consumerSecret"=>$this->user->consumerSecret,"cloudTokenExDate"=>$this->user->cloudTokenExDate,"cloudToken"=>$this->user->cloudToken, "holo_unit"=>$this->user->holo_unit, "plugin_unit"=>$this->user->plugin_unit,"user_traffic"=>$this->user->user_traffic],$this->config->product_cat,$this->config,1,$holoo_cat,$wc_cat_id)->onConnection($this->user->queue_server)->onQueue("low");
                    }
                }
                else{
                    UpdateProductFindStep2::dispatch((object)["queue_server"=>$this->user->queue_server,"id"=>$this->user->id,"siteUrl"=>$this->user->siteUrl,"serial"=>$this->user->serial,"apiKey"=>$this->user->apiKey,"holooDatabaseName"=>$this->user->holooDatabaseName,"consumerKey"=>$this->user->consumerKey,"consumerSecret"=>$this->user->consumerSecret,"cloudTokenExDate"=>$this->user->cloudTokenExDate,"cloudToken"=>$this->user->cloudToken, "holo_unit"=>$this->user->holo_unit, "plugin_unit"=>$this->user->plugin_unit,"user_traffic"=>$this->user->user_traffic],$this->config->product_cat,$this->config,1,$holoo_cat,$wc_cat)->onConnection($this->user->queue_server)->onQueue("low");
                }


            }
        }





    }





    public function fetchAllWCProdsPaging($published=false,$category=null,$page=1):array
    {

        if($published){
            $status= "status=publish&" ;
        }
        else{
            $status= "";
        }
        if($category){
            $category= "category=$category&";
        }
        else{
            $category= "";
        }
        $curl = curl_init();

        $products = [];
        $all_products = [];
        $per_page = $this->capacity_per_page;
        try {
            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->user->siteUrl.'/wp-json/wc/v3/products?'.$status.$category.'meta=_holo_sku&page='.$page.'&per_page='.$per_page,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 60, // Set timeout to 20 seconds
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_USERAGENT => 'Holoo',
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_USERPWD => $this->user->consumerKey. ":" . $this->user->consumerSecret,
            ));

            $response = curl_exec($curl);
            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if($httpcode === 200 and $response){
                $products = json_decode($response);
                if(is_array($products) and count($products)>0){
                    $all_products = array_merge($all_products,$products);
                }
                else{
                    if($page==1){
                        log::error("error in WCProds not array for user id ".$this->user->id);
                        log::warning("get http code ".$httpcode." for wc number ".$page." for user: ".$this->user->id);
                        log::error(json_encode($products));
                    }

                }
            }
            else{
                log::error("error in fetchAllWCProdsPaging in UpdateProductFind for get wc product. get http code ".$httpcode);
            }

        }
        catch(\Throwable $th){
            log::error("error in fetchAllWCProds ".$th->getMessage());

        }




        curl_close($curl);

        return $all_products;



    }


    /**
     * Handle the failing job.
     *
     *
     * @return void
     */
    public function failed()
    {
        log::warning("failed to update product find");
        log::warning("for website id : ".$this->user->siteUrl);
        $this->delete();
    }


    public function checkUserInString($inputString) {
        // جستجو برای "user" در استرینگ
        $position = strpos($inputString, 'user');

        // اگر "user" در استرینگ وجود داشته باشد، مقدار true را برگردان
        if ($position !== false) {
            return true;
        } else {
            // در غیر این صورت، مقدار false را برگردان
            return false;
        }
    }
}
