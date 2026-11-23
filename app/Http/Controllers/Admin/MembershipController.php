<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Services\ActiveContext;
use App\Services\MembershipAdministration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function revoke(Request $request, ActiveContext $context, MembershipAdministration $administration, int $membership): RedirectResponse
    {
        $acting = $context->require($request, 'institution_admin', 'platform_admin');
        $administration->revoke($request->user(), $acting, Membership::query()->findOrFail($membership));

        return back()->with('status', 'Appartenance retirée. Les accès correspondants sont fermés ; le compte et son espace individuel sont conservés.');
    }
}
