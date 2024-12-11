<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;


class updateWCSingleProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $params;
    public $flag;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($user,$params,$flag)
    {
        Log::info(' queue update product start');
        $this->user=$user;
        $this->params=$params;
        $this->flag=$flag;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('update product for flag ' . $this->flag);
        $curl = curl_init();
        $meta = array(
            (object)array(
                'key' => 'wholesale_customer_wholesale_price',
                'value' => $this->params["wholesale_customer_wholesale_price"]
            )
        );
        $data=[
            "regular_price"=>(string)$this->params['regular_price'],
            "sale_price"=>((int)$this->params['sale_price']==0) ? null : (string) $this->params['sale_price'] ,
            //"wholesale_customer_wholesale_price"=>$this->params['wholesale_customer_wholesale_price'],
            "stock_quantity"=>(int)$this->params['stock_quantity'],
            "manage_stock" => true,
            "name"=>$this->params['name'],
            "meta_data"=>$meta,
        ];
        $data = json_encode($data);
        // $this->recordLog('update single product',$data);



        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->user->siteUrl.'/wp-json/wc/v3/products/'. $this->params['id'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_USERAGENT => 'Holoo',
            CURLOPT_USERPWD => $this->user->consumerKey. ":" . $this->user->consumerSecret,
            CURLOPT_HTTPHEADER => array(
              //'Content-Type: multipart/form-data',
              'Content-Type: application/json',
            ),
        ));
        $response = curl_exec($curl);


        $response = json_decode($response);
        log::info("webhook update product");
       // log::info(json_encode($response));
        // $this->recordLog('update single product',json_encode($response));
        curl_close($curl);
    }

    /**
     * The unique ID of the job.
     *
     * @return string
     */
    // public function uniqueId()
    // {
    //     return $this->user->id.$this->flag;
    // }


    /**
     * Handle the failing job.
     *
     *
     * @return void
     */
    public function failed()
    {
        log::warning("failed to update wc single product");
        log::warning("for website id : ".$this->user->siteUrl);
        $this->delete();
    }
}
