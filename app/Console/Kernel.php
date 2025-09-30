<?php

namespace App\Console;

use Spatie\ShortSchedule\ShortSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\CustomTokenDeposit::class,
        Commands\TokenDepositCommand::class,
        Commands\AdjustCustomTokenDeposit::class,
        Commands\BotOrderRemoveCommand::class,
        Commands\UpdateCoinUsdRate::class,
        Commands\StakingInvestmentReturn::class,
        \JoeDixon\Translation\Console\Commands\SynchroniseMissingTranslationKeys::class,
        Commands\ClearFailedJob::class,

    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $setting = settings([
            'cron_coin_rate_status',
            'cron_coin_rate',
            'cron_token_deposit_status',
            'cron_token_deposit',
            'cron_token_deposit_adjust',
            'cron_token_adjust_deposit_status',
        ]);

        $coinRate = $setting['cron_coin_rate'] ?? 10;
        if (isset($setting['cron_coin_rate_status']) && $setting['cron_coin_rate_status'] == STATUS_ACTIVE) {
            $schedule->command('update-coin-usd-rate')->cron('*/' . $coinRate . ' * * * *');
        }
        // $tokenDiposit = $setting['cron_token_deposit'] ?? 10;
        // $tokenDepositAdjust = $setting['cron_token_deposit_adjust'] ?? 20;
        // if(isset($setting['cron_token_deposit_status']) && $setting['cron_token_deposit_status'] == STATUS_ACTIVE) {
        //     $schedule->command('token-block-deposit-command')->cron('*/' . $tokenDiposit . ' * * * *');
        //     $schedule->command('command:erc20token-deposit')->cron('*/' . $tokenDiposit . ' * * * *');
        //     $schedule->command('custom-token-deposit')->cron('*/' . $tokenDiposit . ' * * * *');
        // }

        // if(isset($setting['cron_token_adjust_deposit_status']) && $setting['cron_token_adjust_deposit_status'] == STATUS_ACTIVE) {
        //     $schedule->command('adjust-token-deposit')->cron('*/' . $tokenDepositAdjust . ' * * * *');
        // }

        if (allsetting('enable_bot_trade') == STATUS_ACTIVE) {
            $schedule->command('bot-order:remove')->everyFifteenMinutes(); // bot-order:remove 
        }

        $schedule->command('staking:make-complete')->dailyAt('23:59');
        $schedule->command('staking:give-payment')->dailyAt('12:00');

        $schedule->command('clear-failed-job')->daily();
        if (env('APP_MODE') == 'demo') {
            $schedule->command('clear-big-order')->everyTenMinutes();
        }

        $schedule->command('adjust-token-deposit')->everyThirtyMinutes();
    }


    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
