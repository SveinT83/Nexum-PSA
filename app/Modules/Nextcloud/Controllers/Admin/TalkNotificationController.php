<?php

namespace App\Modules\Nextcloud\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Nextcloud\Actions\ManageTalkNotifications;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudTalkDestination;
use Illuminate\Http\Request;

/** Existing connection workspace owns ordinary Talk setup; controls execute real shared operations. */
class TalkNotificationController extends Controller
{
    public function refresh(Request $request, NextcloudConnection $connection, ManageTalkNotifications $actions)
    {
        $actions->refresh($request, $connection);

        return $this->back($connection, 'success', 'Talk conversations refreshed. Choose destinations and save Talk settings.');
    }

    public function update(Request $request, NextcloudConnection $connection, ManageTalkNotifications $actions)
    {
        $actions->update($request, $connection);

        return $this->back($connection, 'success', 'Talk notification settings saved.');
    }

    public function create(Request $request, NextcloudConnection $connection, ManageTalkNotifications $actions)
    {
        $result = $actions->create($request, $connection);

        return $this->back($connection, $result['success'] ? 'success' : 'warning', $result['success']
            ? 'Private Talk conversation created and verified. Select and enable it separately.'
            : 'Talk group creation is unresolved. Reconcile before creating another conversation.');
    }

    public function test(Request $request, NextcloudConnection $connection, NextcloudTalkDestination $destination, ManageTalkNotifications $actions)
    {
        $result = $actions->test($request, $connection, $destination);

        return $this->back($connection, $result['success'] ? 'success' : 'warning', $result['success']
            ? 'Test message verified in the selected conversation. Human receipt is not verified.'
            : 'Talk test is blocked or unverified. Inspect the recorded result before retrying.');
    }

    private function back(NextcloudConnection $connection, string $status, string $message)
    {
        return redirect()->to(route('tech.admin.nextcloud.connections.show', $connection).'#talk-notifications')->with($status, $message);
    }
}
