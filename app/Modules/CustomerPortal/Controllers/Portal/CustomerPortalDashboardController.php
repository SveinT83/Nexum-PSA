<?php

namespace App\Modules\CustomerPortal\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\CustomerPortal\Support\CustomerPortalContextResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerPortalDashboardController extends Controller
{
    public function __invoke(Request $request, CustomerPortalContextResolver $resolver): View
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');
        $context->account->forceFill(['last_login_at' => now()])->save();

        return view('customerportal::Portal.dashboard', [
            'context' => $context,
            'memberships' => $resolver->validMemberships($context->account),
        ]);
    }
}
