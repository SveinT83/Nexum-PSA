<?php

namespace App\Modules\Commercial\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Commercial\Actions\ExecuteCommercialCommand;
use App\Modules\Commercial\Support\CommercialRevision;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Transport boundary: current authority and exact owning record locks precede replay/revision. */
abstract class CommercialApiController extends Controller
{
    protected function command(Request $request, ?Model $owner, callable $apply, bool $required = true, ?callable $revisionCheck = null): array
    {
        return app(ExecuteCommercialCommand::class)->handle($request, function () use ($request, $owner) {
            $actor = User::query()->findOrFail($request->user()->id);
            $actor->withAccessToken($request->user()->currentAccessToken());
            $request->setUserResolver(fn () => $actor);
            app(DomainApiAuthorization::class)->authorize($request, $request->route()->defaults['commercial_permission']);
            if ($owner) {
                $owner->newQuery()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $owner->refresh();
            }
        }, function () use ($request, $owner, $apply, $required, $revisionCheck) {
            if ($revisionCheck) {
                $revisionCheck();
            } elseif ($owner) {
                CommercialRevision::check($request, $owner, $required);
            }

            return $apply();
        }, $required);
    }
}
