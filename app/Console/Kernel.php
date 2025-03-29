<?php

namespace App\Console;


use Illuminate\Support\Facades\Log;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        log::info("schedule test");

        // resend factor
        $schedule->call('App\Http\Controllers\HolooController@scPayedInvoice')->name('resend factor')->everyFiveMinutes();

    }
}
