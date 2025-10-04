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
use App\Model\Wallet;
use App\Model\WalletSwapHistory;
use App\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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

    public function walletWithdrawalProcess(WithdrawalRequest $request): JsonResponse
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
                    $history->coin_type = 'MIND';
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
}
