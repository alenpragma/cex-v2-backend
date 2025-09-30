<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminBankRequest;
use App\Http\Services\BankService;
use App\Http\Services\CountryService;
use Illuminate\Http\Request;

class AdminBankController extends Controller
{
    private $bankService;

    public function __construct()
    {
        $this->bankService = new BankService();
        $this->countryService = new CountryService();
    }


    public function bankList()
    {
        try{
            $data['title'] = __('Bank List');
            $data['banks'] = $this->bankService->getAll();

            return view('admin.admin-bank.list',$data);

        } catch (\Exception $e) {
            storeException("adminBankList",$e->getMessage());
        }
    }

    public function bankAdd(){
        $data['title'] = __('Add New Bank');
        $data['button_title'] = __('Save');
        $data['countries'] = $this->countryService->getActiveCountries();

        return view('admin.admin-bank.addEdit', $data);
    }

    public function bankSave(AdminBankRequest $request){
        $response = $this->bankService->saveBank($request);

        if ($response['success'] == true) {
            return redirect()->route('adminBankList')->with(['success'=> $response['message']]);
        } else {
            return redirect()->back()->with(['dismiss'=> $response['message']]);
        }
    }


    public function bankEdit($id)
    {
        $data['title'] = __('Update Bank');

        $response = $this->bankService->getBank($id,true);
        if($response['success']==true)
        {
            $data['countries'] = $this->countryService->getActiveCountries();
            $data['item'] = $response['item'];

            return view('admin.admin-bank.addEdit', $data);
        }else {
            return redirect()->back()->with("dismiss",__('Bank not found!'));
        }

    }

    public function bankDelete($id)
    {
        $response = $this->bankService->deleteBank($id,true);

        if ($response['success'] == true) {
            return redirect()->back()->with("success",__('Deleted successfully'));
        } else {
            return redirect()->back()->with("dismiss",$response['message']);
        }
    }

    public function bankStatusChange(Request $request)
    {
        $response = $this->bankService->changeStatus($request->bank_id);

        if ($response['success'] == true) {
            return response()->json(['message'=>$response['message']]);
        } else {
            return response()->json(['message'=>$response['message']]);
        }
    }
}
