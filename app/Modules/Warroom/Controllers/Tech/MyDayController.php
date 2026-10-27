<?php

namespace App\Modules\Warroom\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Warroom\Queries\MyDayWorkQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyDayController extends Controller
{
    /** Personal counts and overdue focus share one exact, source-authorized scope. */
    public function __invoke(Request $request, MyDayWorkQuery $query): View
    {
        $input = $request->validate(['focus' => 'sometimes|in:overdue', 'page' => 'sometimes|integer|min:1|max:10000']);

        return view('warroom::Tech.my-day', ['myDay' => $query->forUser($request->user(), null,
            isset($input['focus']), (int) ($input['page'] ?? 1), 25)]);
    }
}
