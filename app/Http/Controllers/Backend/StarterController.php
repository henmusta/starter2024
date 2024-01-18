<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use http\Client\ConnectionException;


class StarterController extends Controller
{

    // protected $baseUrl;

    public function __construct()
    {
    //   $this->baseUrl = config('app.api');
    }

    public function get_data(Request $request){
        $data = "";
        $url = 'http://localhost:8011/v1/';
        try {
            $responseApi = Http::retry(3, 100)->withHeaders([
              'Content-Type' => 'application/json',
              'Accept' => 'application/json'
            ])->post($url . "inquiryListPrakarsa", [
                
            ]);
            if ($responseApi->successful()) {
                $data = json_decode($responseApi);
                return $data;
            } else {
              DB::rollBack();
              return "Server API Error";
            }
          } catch (ConnectionException $e) {
            DB::rollBack();
            return "Server API Error";
        }
    }



    // public function getreport(Request $request)
    // {
    //       $validator = Validator::make($request->all(), [
    //         'bulan' => 'required',
    //         'tahun' => 'required',
    //       ]);
    //       $type = 'post';
    //       $getdata = $this->data($request, $type);
    //       $data = [
    //           'bulan' => $request['bulan'],
    //           'tahun' => $request['tahun'],
    //           'data' =>$getdata ,
    //       ];
    //       return view('backend.bulanankasbon.report', compact('data'));

    // }

    public function index(Request $request)
    {

        $data = $this->get_data($request);
        return view('index', compact('data'));
    }

}
