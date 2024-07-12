<?php

namespace App\Http\Controllers;

use App\Clients\ExchangeRate\ExchangeRateClient;
use App\Http\Resources\CompanyCurrencyResource;
use App\Models\Company;
use App\Models\Integration;
use App\Models\CompanyCurrency;
use App\Models\Organization;
use Illuminate\Http\Request;

use function PHPUnit\Framework\isEmpty;

class CompanyCurrencyController extends Controller
{
    public function getIndex()
    {
        $company = Company::current();
        $integrations = Integration::where(['company_id' => $company->id, 'group' => 'accounting', 'type' => 'quickbooks'])->first();
        $currencies = [];
        if (isset($integrations)) {
            $currencies = CompanyCurrency::where(['company_id' => $company->id, 'type' => 'quickbooks'])->get();
        } else {
            $currencies = CompanyCurrency::where(['company_id'=> $company->id , 'type' => 'exchange-api'])->get();
        }
        return CompanyCurrencyResource::collection($currencies);
    }

    public function postIndex(Request $request)
    {
        $data = $request->validate([
            'companyId' => 'required',
            'currency' => 'required',
        ]);

        $currency = CompanyCurrency::where(['company_id' => $data['companyId'], 'currency' => $data['currency']])->first();
        if (isset($currency)) {
            return abort(403);
        }

        $currency = CompanyCurrency::create([
            'currency'              => $data['currency'],
            'company_id'            => $data['companyId'],
            'type'                  => 'exchange-api',
        ]);

        $exchangeClient  = new ExchangeRateClient();
        $company         = Company::current();
        $defaultCurrency = $company->currency_code;
        $rate            = $exchangeClient->findExchangeRate($currency->currency, $defaultCurrency);
        if (isset($rate)) {
            $currency->rate = $rate;
            $currency->save();
        }

        return new CompanyCurrencyResource($currency);
    }

    public function patchIndex(CompanyCurrency $currency, Request $request)
    {
        $data = $request->validate([
            'rate' => 'required'
        ]);
        $currency->rate =  $data['rate'];
        $currency->save();
        return $currency;
    }

    public function deleteIndex(CompanyCurrency $currency)
    {
        $currency->delete();
        return ['success' => true];
    }

    public function getSync()
    {
        $company = Company::current();
        $company->syncCurrenciesWithQB();
        return $this->getIndex();
    }
}
