<?php

namespace Tests\Unit;

use App\Http\Requests\AutoSupplyPlan\StoreAutoSupplyPlanRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AutoSupplyPlanRequestTest extends TestCase
{
    public function test_horizon_28_days_is_allowed_for_existing_frontend_default(): void
    {
        $rules = (new StoreAutoSupplyPlanRequest())->rules();

        $validator = Validator::make([
            'integration_id' => 17,
            'horizon_days' => 28,
        ], $rules);

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE));
    }
}
