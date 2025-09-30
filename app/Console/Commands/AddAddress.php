<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\User;
use GuzzleHttp\Client;
use App\Model\Wallet;
use Carbon\Carbon;

class AddAddress extends Command
{
    protected $signature = 'create:address';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Address';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        $users= User::get();



        foreach ($users as $row) {

                $update_user = User::where('id',$row->id)->first();
                $uid = $row->id;

                $client = new Client();
                $response = $client->post('https://evm.blockmaster.info/api/create-wallet', [
                'form_params' => [
                'uid' => $uid,
                ],
                ]);

                $responseBody = json_decode($response->getBody(), true);
              //  dd($responseBody);
                $update_user->deposit_address = $responseBody['address'];
                $update_user->private_key = $responseBody['key'];
                $update_user->save();



        }
        // $users= User::all();
        // foreach($users as $user)
        // {
        //   $wallet = new Wallet();
        //   $wallet->user_id = $user->id;
        //   $wallet->name = 'BTC Wallet';
        //   $wallet->coin_id = 1;
        //   $wallet->type = 1;
        //   $wallet->coin_type = 'BTC';
        //   $wallet->status = 1;
        //   $wallet->is_primary = 0;
        //   $wallet->balance = 0.000;
        //   $wallet->created_at = Carbon::now();
        //   $wallet->updated_at = Carbon::now();
        //   $wallet->save();
        //   $new_wallet = new Wallet();
        //   $new_wallet->user_id = $user->id;
        //   $new_wallet->name = 'USDT Wallet';
        //   $new_wallet->coin_id = 2;
        //   $new_wallet->type = 1;
        //   $new_wallet->coin_type = 'USDT';
        //   $new_wallet->status = 1;
        //   $new_wallet->is_primary = 0;
        //   $new_wallet->balance = 0.000;
        //   $new_wallet->created_at = Carbon::now();
        //   $new_wallet->updated_at = Carbon::now();
        //   $new_wallet->save();

        // }


        $this->info('Successfully added address');

      //  $use=((($user['packages']['return_percentage']*$user['packages']['price'])/100)*$sponsor_bonus['royality_bonus']/100)*$income[$i]/100;
    }
}
