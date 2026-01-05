<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\User\NetworkAddressRequest;
use App\Http\Requests\Api\User\WalletRateRequest;
use App\Http\Requests\Api\User\WithdrawalRequest;
use App\Http\Requests\CoinSwapRequest;
use App\Http\Services\TransService;
use App\Http\Services\WalletService;
use App\Http\Services\ProgressStatusService;
use App\Model\Coin;
use App\Model\CurrencyDepositHistory;
use App\Model\CurrencyWithdrawalHistory;
use App\Model\Wallet;
use App\Model\WalletSwapHistory;
use App\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    public WalletService $service;
    public TransService $transService;
    public ProgressStatusService $progressService;

    public function __construct()
    {
        $this->service = new WalletService();
        $this->transService = new TransService();
        $this->progressService = new ProgressStatusService();
    }

    public function walletList(Request $request): JsonResponse
    {
        return $this->handlerApiResponse(function () use ($request): array {
            return $this->service->userWalletList(auth()->id(), $request);
        });
    }

    public function walletDeposit(string $coinType): JsonResponse
    {
        return $this->handlerApiResponse(function () use ($coinType) {
            return $this->service->userWalletDeposit(auth()->id(), $coinType);
        });
    }

    public function walletWithdrawal(string $coinType): JsonResponse
    {
        return $this->handlerApiResponse(function () use ($coinType) {
            return $this->service->userWalletWithdrawal(auth()->id(), $coinType);
        });
    }

    public function walletWithdrawalProcesss(WithdrawalRequest $request): JsonResponse
    {
        return $this->handlerApiResponse(function () use ($request) {
            return $this->transService->withdrawalProcess(auth()->user(), $request);
        });
    }

    public function walletHistoryApp(Request $request): JsonResponse
    {
        return $this->handlerApiResponse(function () use ($request): array {
            return $this->service->walletHistoryApp($request, auth()->id());
        });
    }

    public function coinSwapHistoryApp(Request $request): JsonResponse
    {
        return $this->handlerApiResponse(function () use ($request): array {
            return $this->service->coinSwapHistoryApp($request);
        });
    }

    /**
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function coinSwapApp()
    {
        $data['title'] = __('Coin Swap');
        $data['wallets'] = Wallet::join('coins', 'coins.id', '=', 'wallets.coin_id')
            ->where(['wallets.user_id' => Auth::id(), 'wallets.type' => PERSONAL_WALLET, 'coins.status' => STATUS_ACTIVE])
            ->orderBy('wallets.id', 'ASC')
            ->select('wallets.*')
            ->get();
        return response()->json(['success' => true, 'data' => $data, 'message' => __('Coin Swap Data')]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * get rate of coin
     */
    public function getRateApp(WalletRateRequest $request)
    {
        $data = $this->service->get_wallet_rate($request);
        return response()->json($data);
    }

    /**
     * @param CoinSwapRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function swapCoinApp(CoinSwapRequest $request)
    {
        try {
            $data['success'] = false;
            $data['message'] = __('Something went wrong');
            $fromWallet = Wallet::where(['id' => $request->from_coin_id])->first();
            // if(isset($request->code)){
            //     $response = checkTwoFactor("two_factor_swap",$request);
            //     if(!$response["success"]){
            //         return response()->json($response);
            //     }
            // }
            if (!empty($fromWallet) && $fromWallet->type == CO_WALLET) {
                return response()->json($data);
            }
            $response = $this->service->get_wallet_rate($request);
            if ($response['success'] == false) {
                return response()->json($data);
            }
            $swap_coin = $this->service->coinSwap($response['from_wallet'], $response['to_wallet'], $response['convert_rate'], $response['amount'], $response['rate']);
            if ($swap_coin['success'] == true) {
                $data['success'] = true;
                $data['message'] = $swap_coin['message'];
            } else {
                $data['success'] = false;
                $data['message'] = $swap_coin['message'];
            }
            return response()->json($data);
        } catch (\Exception $e) {
            storeException('swapCoinApp ', $e->getMessage());
            return response()->json(responseData(false, __("Something went wrong")));
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCoinSwapDetailsApp(Request $request)
    {
        $wallet = Wallet::find($request->id);
        $data['wallets'] = Coin::select('coins.*', 'wallets.name as wallet_name', 'wallets.id as wallet_id', 'wallets.balance')
            ->join('wallets', 'wallets.coin_type', '=', 'coins.coin_type')
            ->where('coins.status', STATUS_ACTIVE)
            ->where('wallets.user_id', Auth::id())
            ->where('coins.coin_type', '!=', $wallet->coin_type)
            ->get();

        return response()->json($data);
    }

    public function getWalletNetworkAddress(NetworkAddressRequest $request): JsonResponse
    {
        return $this->handlerApiResponse(function () use ($request) {
            return $this->service->getWalletNetworkAddress($request, auth()->id());
        });
    }

    public function preWithdrawalProcess(Request $request): JsonResponse
    {
        return $this->handlerApiResponse(function () use ($request): array {
            return $this->transService->preWithdrawalProcess(auth()->id(), $request);
        });
    }

    public function getWalletBalanceDetails(Request $request)
    {
        return $this->handlerApiResponse(function () use ($request) {
            return $this->service->getWalletBalanceDetails($request);
        });
    }

    public function walletTotalValue(): JsonResponse
    {
        return $this->handlerApiResponse(function () {
            return $this->service->userWalletTotalValue(auth()->id());
        });
    }



    public function checkDesposit()
    {

        $client = new Client();
        $jobs = DB::table('check_deposit_job')->get();

        if ($jobs->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No deposit jobs found.',
                'data' => [],
            ]);
        }

        $successCount = 0;
        $errors = [];

        foreach ($jobs as $job) {
            $jobCreateTime = Carbon::parse($job->job_created_at); // Updated naming convention

            // Delete old jobs (>= 5 minutes)
            if ($jobCreateTime->diffInMinutes(Carbon::now()) >= 30) {
                DB::table('check_deposit_job')->where('userId', $job->userId)->delete();
                continue;
            }

            $user = User::find($job->userId);
            if (!$user) {
                $errors[] = "User not found with ID: {$job->userId}";
                continue;
            }
            for($i =0; $i<=1; $i++){
                try {

                    $response = $client->post('https://evm.blockmaster.info/api/deposit', [
                        'json' => [
                            'type' => $i == 0 ? 'native' : 'token',
                            'chain_id' => '9996',
                            'user_id' => '7',
                            'to' => $user->deposit_address,
                            'token_address' => '0xaC264f337b2780b9fd277cd9C9B2149B43F87904',
                        ],
                        'headers' => [
                            'Accept' => 'application/json',
                            'Bearer-Token' => $user->private_key
                        ],
                        'timeout' => 50,
                    ]);

                    $responseData = json_decode($response->getBody(), true);

                    if (!is_array($responseData)) {
                        $errors[] = "response: $responseData";
                        continue;
                    }

                    if (isset($responseData['status']) && $responseData['status'] === false) {
                        $errors[] = $responseData['message'] ?? "Unknown error for user ID:";
                        continue;
                    }

                    $txHash = $responseData['txHash'] ?? null;
                    $amount = $responseData['amount'] ?? null;
                    $wallet = Wallet::where('user_id',$user->id)->where('coin_type','MIND')->first();
                    if (!$wallet){
                        return 'wallet not found';
                    }
                    if ($txHash === null || $amount === null) {
                        $errors[] = "Missing txHash or amount for user ID: {$user->id}";
                        continue;
                    }
                    DB::beginTransaction();

                    $history = new CurrencyDepositHistory();
                    $history->user_id = $user->id;
                    $history->payment_id = 1;
                    $history->payment_type =1;
                    $history->wallet_id = $wallet->id;
                    $history->coin_id =$wallet->coin_id;
                    $history->coin_type = $i == 0 ? 'MIND' : 'MUSD';
                    $history->amount =$amount;
                    $history->transaction_id = $txHash;
                    $history->status =1;
                    $history->save();
                    $userEmail = $user->email;
                    $wallet->balance = $wallet->balance+ $amount;
                    $wallet->save();
                    DB::table('check_deposit_job')->where('userId', $job->userId)->delete();
                    DB::commit();
                    try {
                        Mail::send('email.transaction-success', [
                            'logo_url' => 'https://admin.mindchain.info/uploaded_file/uploads/66cde729ee6b61724770089.png',
                            'txHash' => $txHash,
                            'amount' => $amount,
                        ], function ($message) use ($userEmail) {
                            $message->to($userEmail)
                                ->subject('Your Transaction Was Successful');
                        });
                    }catch (\Exception $exception){}
                    sleep(5);
                    $successCount++;
                } catch (\Exception $e) {
                    DB::rollBack();
                    $errors[] = "Exception for user ID {$user->id}: " . $e->getMessage();
                }
                sleep(5);
            }
        }

        return response()->json([
            'success' => true,
            'message' => "{$successCount} job(s) processed successfully.",
            'errors' => $errors,
        ]);
    }

    public function walletWithdrawalProcess(Request $request)
    {
        $rules = [
            'coin_type' => 'required',
            'amount'=> 'required',
            //  'network'=> 'required',
            'address'=> 'required',

        ];

        // Validate the request
        $validator = Validator::make($request->all(), $rules);

        $user = $request->user();

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }
//        $code = UserOtp::where('status',0)->where('user_id',Auth::id())->where('code',$request->otp)->orderBy('id','desc')->first();
//        if($code == null)
//        {
//            return response()->json(['error'=>200,'message' => 'Code not found or expired']);
//        }
//        $date = Carbon::parse($code->date)->addMinutes(2);
//        if(Carbon::now() > $date)
//        {
//            return response()->json(['error'=>200,'message' => 'Code expired']);
//        }
        $code = 1;
        if($code != null)
        {
//            $code->status = 1;
//            $code->save();
            $client= new Client();

            if ($request->coin_type == 'MIND') {

                DB::beginTransaction();

                try {
                    // Row-level lock
                    $data['balance'] = Wallet::where('user_id', Auth::id())
                        ->where('coin_type', $request->coin_type)
                        ->lockForUpdate()
                        ->first();

                    if (!$data['balance']) {
                        DB::rollBack();
                        return response()->json(['error' => 404, 'message' => 'Wallet not found']);
                    }

                    $settings = Coin::where('coin_type', $request->coin_type)->first();

                    // Min/Max withdrawal check
                    if ($request->amount < $settings->minimum_withdrawal || $request->amount > $settings->maximum_withdrawal) {
                        DB::rollBack();
                        return response()->json(['error' => 400, 'message' => 'Your amount is below minimum or above maximum limit']);
                    }

                    // Balance check
                    if ($data['balance']->balance < $request->amount) {
                        DB::rollBack();
                        return response()->json(['error' => 400, 'message' => 'Insufficient Fund!']);
                    }

                    $final_amount = $request->amount - ($request->amount * $settings->withdrawal_fees / 100);

                    $response = $client->post('https://evm.blockmaster.info/api/payout', [
                        'form_params' => [
                            'to' => $request->address,
                            'amount' => $final_amount,
                            'user_id' => 7,
                            'type' => 'native',
                            'chain_id' => 9996,
                        ],
                    ]);

                    $responseBody = json_decode($response->getBody(), true);

                    if (isset($responseBody['txHash']) && $responseBody['txHash'] != null) {
                        // Deduct balance
                        $data['balance']->balance -= $request->amount;
                        $data['balance']->save();

                        // Save history
                        $history = new CurrencyWithdrawalHistory();
                        $history->user_id = Auth::id();
                        $history->wallet_id = $data['balance']->id;
                        $history->coin_id = $data['balance']->coin_id;
                        $history->coin_type = $request->coin_type;
                        $history->amount = $request->amount;
                        $history->fees = $request->amount * $settings->withdrawal_fees / 100;
                        $history->payment_info = $responseBody['txHash'];
                        $history->status = 1;
                        $history->save();

                        DB::commit();
                        return response()->json(['success' => 200, 'message' => 'Successfully withdrawn']);
                    } else {
                        DB::rollBack();
                        return response()->json(['success' => 400, 'message' => 'Something went wrong']);
                    }

                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json(['error' => 500, 'message' => $e->getMessage()]);
                }
            }

            //mind withdraw end

            elseif ($request->coin_type == 'MUSD') {

                DB::beginTransaction();

                try {
                    // Row-level lock to prevent double withdraw
                    $data['balance'] = Wallet::where('user_id', Auth::id())
                        ->where('coin_type', $request->coin_type)
                        ->lockForUpdate()
                        ->first();

                    if (!$data['balance']) {
                        DB::rollBack();
                        return response()->json(['error' => 404, 'message' => 'Wallet not found']);
                    }

                    $settings = Coin::where('coin_type', $request->coin_type)->first();

                    // Min/Max withdrawal check
                    if ($request->amount < $settings->minimum_withdrawal || $request->amount > $settings->maximum_withdrawal) {
                        DB::rollBack();
                        return response()->json(['error' => 400, 'message' => 'Your amount is below minimum or above maximum limit']);
                    }

                    // Balance check
                    if ($data['balance']->balance < $request->amount) {
                        DB::rollBack();
                        return response()->json(['error' => 400, 'message' => 'Insufficient Fund!']);
                    }

                    $final_amount = $request->amount - ($request->amount * $settings->withdrawal_fees / 100);

                    $response = Http::post('https://evm.blockmaster.info/api/payout', [
                        'form_params' => [
                            'to' => $request->address,
                            'amount' => $final_amount,
                            'user_id' => 7,
                            'type' => 'token',
                            'chain_id' => 9996,
                            'token_address' => '0xaC264f337b2780b9fd277cd9C9B2149B43F87904'
                        ],
                    ]);

                    $responseBody = json_decode($response->getBody(), true);

                    if (isset($responseBody['txHash']) && $responseBody['txHash'] != null) {
                        // Deduct balance
                        $data['balance']->balance -= $request->amount;
                        $data['balance']->save();

                        // Save withdrawal history
                        $history = new CurrencyWithdrawalHistory();
                        $history->user_id = Auth::id();
                        $history->wallet_id = $data['balance']->id;
                        $history->coin_id = $data['balance']->coin_id;
                        $history->coin_type = $request->coin_type;
                        $history->amount = $request->amount;
                        $history->fees = $request->amount * $settings->withdrawal_fees / 100;
                        $history->payment_info = $responseBody['txHash'];
                        $history->status = 1;
                        $history->save();

                        DB::commit();
                        return response()->json(['success' => 200, 'message' => 'Successfully withdrawn']);
                    } else {
                        DB::rollBack();
                        return response()->json(['success' => 400, 'message' => 'Something went wrong']);
                    }

                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json(['error' => 500, 'message' => $e->getMessage()]);
                }
            }




            elseif($request->coin_type == 'BMIND')
            {

                $data['balance'] = Wallet::where('user_id',Auth::id())->where('coin_type',$request->coin_type)->first();
                //  dd($data['withdraw']);
                $settings = Coin::where('coin_type',$request->coin_type)->first();
// Define validation rules


                if ($request->amount < $settings->minimum_withdrawal || $request->amount > $settings->maximum_withdrawal) {
                    // return back()
                    //    ->withErrors($validator)  // Pass validation errors to the view
                    //    ->withInput();
                    return response()->json(['error'=>400,'message' => 'Your amount is minimum or maximum than the required amount']);// Keep the user's input data
                }


                elseif ($data['balance']->balance < $request->amount) {
                    return response()->json(['error'=>400,'message' => 'Insufficient Fund!']);
                }

                else
                {
                    $final_amount = $request->amount - ($request->amount*$settings->withdrawal_fees/100);

                    $response = Http::post('https://web3.blockmaster.info/api/send-usdt-transaction', [
                        'form_params' => [
                            'to' => $request->address,
                            'from' => '0x9682a5c5241fb95d83f9f51897c7281ea69b740a',
                            'value' => $final_amount,
                            'chain_id' => 9996,
                            'sender_private_key' => '0x0a970f4e04648ef64171601f52da80b2782e8453d19b234c90edf49b4ba3455c',
                            'jwt_token'=> '4ZYDGRJCNH6NV95',
                            'secret_key'=> '4ZYDGRJCNH6NV95',
                            'domain_name' => 'mindchain.info',
                            'contact_address'=> '0x781Ee88b2558e5c9030C0d436de3F7eDD38d61A2',
                        ],
                    ]);

                    $responseBody = json_decode($response->getBody(), true);
                    if(isset($responseBody['tx_hash']) && $responseBody['tx_hash'] != null)
                    {
                        $data['balance']->balance= $data['balance']->balance - $request->amount;
                        $data['balance']->save();;

                        $history = new CurrencyWithdrawalHistory();
                        $history->user_id = Auth::id();
                        //  $history->wallet_id = 1;
                        // $history->payment_type =1;
                        $history->wallet_id = $data['balance']->id;
                        $history->coin_id =$data['balance']->coin_id;
                        $history->coin_type = $request->coin_type;
                        $history->amount =$request->amount;
                        $history->fees =$request->amount*$settings->withdrawal_fees/100;
                        $history->payment_info = $responseBody['tx_hash'];
                        $history->status =1;
                        $history->save();


                        return response()->json(['success'=>200,'message' => 'Successfully withdrawn']);
                    }else
                    {
                        return response()->json(['success'=>400,'message' => 'Something went wrong']);
                    }

                }



            }
            elseif($request->coin_type == 'PMIND')
            {

                $data['balance'] = Wallet::where('user_id',Auth::id())->where('coin_type',$request->coin_type)->first();
                //  dd($data['withdraw']);
                $settings = Coin::where('coin_type',$request->coin_type)->first();
// Define validation rules


                if ($request->amount < $settings->minimum_withdrawal || $request->amount > $settings->maximum_withdrawal) {
                    // return back()
                    //    ->withErrors($validator)  // Pass validation errors to the view
                    //    ->withInput();
                    return response()->json(['error'=>400,'message' => 'Your amount is minimum or maximum than the required amount']);// Keep the user's input data
                }


                elseif ($data['balance']->balance < $request->amount) {
                    return response()->json(['error'=>400,'message' => 'Insufficient Fund!']);
                }

                else
                {
                    $final_amount = $request->amount - ($request->amount*$settings->withdrawal_fees/100);

                    $response = Http::post('https://web3.blockmaster.info/api/send-usdt-transaction', [
                        'form_params' => [
                            'to' => $request->address,
                            'from' => '0x9682a5c5241fb95d83f9f51897c7281ea69b740a',
                            'value' => $final_amount,
                            'chain_id' => 9996,
                            'sender_private_key' => '0x0a970f4e04648ef64171601f52da80b2782e8453d19b234c90edf49b4ba3455c',
                            'jwt_token'=> '4ZYDGRJCNH6NV95',
                            'secret_key'=> '4ZYDGRJCNH6NV95',
                            'domain_name' => 'mindchain.info',
                            'contact_address' => '0x75E218790B76654A5EdA1D0797B46cBC709136b0',
                        ],
                    ]);

                    $responseBody = json_decode($response->getBody(), true);
                    if(isset($responseBody['tx_hash']) && $responseBody['tx_hash'] != null)
                    {
                        $data['balance']->balance= $data['balance']->balance - $request->amount;
                        $data['balance']->save();

                        $history = new CurrencyWithdrawalHistory();
                        $history->user_id = Auth::id();
                        //  $history->wallet_id = 1;
                        // $history->payment_type =1;
                        $history->wallet_id = $data['balance']->id;
                        $history->coin_id =$data['balance']->coin_id;
                        $history->coin_type = $request->coin_type;
                        $history->amount =$request->amount;
                        $history->fees =$request->amount*$settings->withdrawal_fees/100;
                        $history->payment_info = $responseBody['tx_hash'];
                        $history->status =1;
                        $history->save();


                        return response()->json(['success'=>200,'message' => 'Successfully withdrawn']);
                    }else
                    {
                        return response()->json(['success'=>400,'message' => 'Something went wrong']);
                    }

                }



            }

            //end musd withdraw

            elseif ($request->coin_type == 'USDT') {

                DB::beginTransaction();

                try {
                    // Row lock
                    $data['balance'] = Wallet::where('user_id', Auth::id())
                        ->where('coin_type', $request->coin_type)
                        ->lockForUpdate()
                        ->first();

                    if (!$data['balance']) {
                        DB::rollBack();
                        return response()->json(['error' => 404, 'message' => 'Wallet not found']);
                    }

                    $settings = Coin::where('coin_type', $request->coin_type)->first();

                    // Min/Max check
                    if ($request->amount < $settings->minimum_withdrawal || $request->amount > $settings->maximum_withdrawal) {
                        DB::rollBack();
                        return response()->json(['error' => 400, 'message' => 'Your amount is below minimum or above maximum limit']);
                    }

                    // Balance check
                    if ($data['balance']->balance < $request->amount) {
                        DB::rollBack();
                        return response()->json(['error' => 400, 'message' => 'Insufficient Fund!']);
                    }

                    $final_amount = $request->amount - ($request->amount * $settings->withdrawal_fees / 100);

                    $response = Http::post('https://evm.blockmaster.info/api/payout', [
                        'form_params' => [
                            'to' => $request->address,
                            'amount' => $final_amount,
                            'user_id' => 7,
                            'type' => 'token',
                            'chain_id' => 56,
                            'token_address' => '0x55d398326f99059fF775485246999027B3197955'
                        ],
                    ]);

                    $responseBody = json_decode($response->getBody(), true);

                    if (isset($responseBody['txHash']) && $responseBody['txHash'] != null) {
                        // Deduct balance
                        $data['balance']->balance -= $request->amount;
                        $data['balance']->save();

                        // History
                        $history = new CurrencyWithdrawalHistory();
                        $history->user_id = Auth::id();
                        $history->wallet_id = $data['balance']->id;
                        $history->coin_id = $data['balance']->coin_id;
                        $history->coin_type = $request->wallet_id;
                        $history->amount = $request->amount;
                        $history->fees = $request->amount * $settings->withdrawal_fees / 100;
                        $history->payment_info = $responseBody['txHash'];
                        $history->status = 1;
                        $history->save();

                        DB::commit();
                        return response()->json(['success' => 200, 'message' => 'Successfully withdrawn']);
                    } else {
                        DB::rollBack();
                        return response()->json(['success' => 400, 'message' => 'Something went wrong']);
                    }

                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json(['error' => 500, 'message' => $e->getMessage()]);
                }
            }




        }
    }


    // user to user transfer 

    public function userToUserTransfer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'receiver'  => 'required|string',
            'coin_type' => 'required|string|exists:wallets,coin_type',
            'amount'    => 'required|numeric|min:0.0001',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        $sender = auth()->user();

        // Receiver find by email OR deposit_address (users table)
        $receiver = User::where('email', $request->receiver)
            ->orWhere('deposit_address', $request->receiver)
            ->first();

        if (!$receiver) {
            return response()->json([
                'success' => false,
                'message' => 'Receiver not found'
            ], 404);
        }

        if ($receiver->id === $sender->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot transfer to yourself'
            ], 400);
        }

        DB::beginTransaction();
        try {

            // Sender wallet
            $senderWallet = Wallet::where('user_id', $sender->id)
                ->where('coin_type', $request->coin_type)
                ->lockForUpdate()
                ->first();

            // Receiver wallet
            $receiverWallet = Wallet::where('user_id', $receiver->id)
                ->where('coin_type', $request->coin_type)
                ->lockForUpdate()
                ->first();

            if (!$senderWallet || !$receiverWallet) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found'
                ], 404);
            }

            $amount = (float) $request->amount;

            if ($senderWallet->balance < $amount) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance'
                ], 400);
            }

            $senderWallet->balance   -= $amount;
            $receiverWallet->balance += $amount;

            $senderWallet->save();
            $receiverWallet->save();

            // Save transfer history
            DB::table('user_transfers')->insert([
                'from_user_id'       => $sender->id,
                'to_user_id'         => $receiver->id,
                'wallet_id'          => $senderWallet->id,
                'receiver_wallet_id' => $receiverWallet->id,
                'coin_type'          => $request->coin_type,
                'amount'             => $amount,
                'fees'               => 0,
                'status'             => 1,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transfer successful'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong'
            ], 500);
        }
    }

    public function userToUserTransferHistory(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = DB::table('user_transfers as ut')
            ->join('users as sender', 'sender.id', '=', 'ut.from_user_id')
            ->join('users as receiver', 'receiver.id', '=', 'ut.to_user_id')
            ->where(function ($q) use ($user) {
                $q->where('ut.from_user_id', $user->id)
                ->orWhere('ut.to_user_id', $user->id);
            })
            ->select([
                'ut.id',
                'ut.coin_type',
                'ut.amount',
                'ut.fees',
                'ut.status',
                'ut.created_at',

                'sender.id as sender_id',
                'sender.email as sender_email',

                'receiver.id as receiver_id',
                'receiver.email as receiver_email',

                DB::raw("
                    CASE 
                        WHEN ut.from_user_id = {$user->id} 
                        THEN 'sent'
                        ELSE 'received'
                    END as type
                ")
            ])
            ->orderBy('ut.id', 'desc');

        // Optional filter by coin_type
        if ($request->filled('coin_type')) {
            $query->where('ut.coin_type', $request->coin_type);
        }

        $histories = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $histories
        ]);
    }



}
