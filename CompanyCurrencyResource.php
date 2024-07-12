<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CompanyCurrencyResource extends JsonResource
{

    public function toArray($request)
    {
        $data =  [
            'id'            => $this->id,
            'name'          => $this->currency,
            'rate'          => $this->rate,
            'type'          => $this->type,
        ];
        return $data;
    }
}
