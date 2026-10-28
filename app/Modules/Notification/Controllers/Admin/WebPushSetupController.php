<?php

namespace App\Modules\Notification\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Actions\ManageWebPushSetup;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Ordinary setup accepts safe contact/explicit choices only; installation secrets never enter this controller. */
final class WebPushSetupController extends Controller
{
    public function index(Request $request, ManageWebPushSetup $actions)
    {
        return view('notification::Admin.web-push.setup', ['setup' => $actions->context($request)]);
    }

    public function initialize(Request $request, ManageWebPushSetup $actions)
    {
        return $this->execute($request, $actions, 'initialize');
    }

    public function check(Request $request, ManageWebPushSetup $actions)
    {
        return $this->execute($request, $actions, 'check');
    }

    public function activation(Request $request, ManageWebPushSetup $actions)
    {
        return $this->execute($request, $actions, 'activation');
    }

    public function rotate(Request $request, ManageWebPushSetup $actions)
    {
        return $this->execute($request, $actions, 'rotate');
    }

    public function rollback(Request $request, ManageWebPushSetup $actions)
    {
        return $this->execute($request, $actions, 'rollback');
    }

    private function execute(Request $request, ManageWebPushSetup $actions, string $method)
    {
        $back = redirect()->route('tech.admin.notification-channels.web-push.setup.index');
        try {
            $result = $actions->{$method}($request);
            $message = ($result['operation']['replayed'] ?? false) ? 'The recorded action was read back. Inspect the current setup state below.' : match ($method) {
                'initialize' => 'Push identity configured. Run readiness checks before explicit activation.',
                'check' => 'Readiness check requested. Refresh its worker result below; no notification was sent.',
                'activation' => $result['context']['identity']['stored_enabled'] ? 'The global Web Push switch is enabled.' : 'The global Web Push switch is disabled.',
                'rotate' => 'Push identity replaced with delivery disabled. Devices must register again after verification and activation.',
                'rollback' => 'The selected identity was restored with delivery disabled. Devices must register again after verification and activation.',
            };

            return $back->with('success', $message);
        } catch (\Illuminate\Validation\ValidationException $error) {
            throw $error;
        } catch (HttpExceptionInterface $error) {
            if (in_array($error->getStatusCode(), [401, 403], true)) {
                throw $error;
            }

            return $back->withErrors(['setup' => $error->getMessage() ?: 'The setup action is blocked; refresh its current state.']);
        } catch (\Throwable) {
            return $back->with('warning', 'The setup outcome could not be verified. Refresh and reconcile the recorded action before retrying.');
        }
    }
}
