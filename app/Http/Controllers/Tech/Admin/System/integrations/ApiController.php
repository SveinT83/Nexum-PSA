<?php

namespace App\Http\Controllers\Tech\Admin\System\integrations;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use L5Swagger\ConfigFactory;
use Illuminate\Support\Facades\Request as RequestFacade;

class ApiController extends Controller
{
    /**
     * Display the API management dashboard.
     */
    public function index()
    {
        // For now, we fetch Sanctum tokens.
        // In a more advanced implementation, we might want to filter or use a custom model
        // if we add IP restrictions and more metadata as per api_management.md
        $apiKeys = PersonalAccessToken::all();

        return view('tech.admin.system.integrations.api.index', compact('apiKeys'));
    }

    /**
     * Show the Swagger UI documentation.
     */
    public function documentation(ConfigFactory $configFactory)
    {
        $documentation = 'default';
        $config = $configFactory->documentationConfig($documentation);

        $useAbsolutePath = config('l5-swagger.documentations.'.$documentation.'.paths.use_absolute_path', true);

        // We need to mimic L5Swagger\Http\Controllers\SwaggerController::api logic
        // to provide all required variables to the view.

        return view('l5-swagger::index', [
            'documentation' => $documentation,
            'documentationTitle' => $config['api']['title'] ?? $documentation,
            'secure' => RequestFacade::secure(),
            'urlToDocs' => route('l5-swagger.'.$documentation.'.docs', $config['paths']['docs_json'] ?? 'api-docs.json', $useAbsolutePath),
            'urlsToDocs' => [$config['api']['title'] ?? $documentation => route('l5-swagger.'.$documentation.'.docs', $config['paths']['docs_json'] ?? 'api-docs.json', $useAbsolutePath)],
            'operationsSorter' => $config['operations_sort'],
            'configUrl' => $config['additional_config_url'],
            'validatorUrl' => $config['validator_url'],
            'useAbsolutePath' => $useAbsolutePath,
        ]);
    }

    /**
     * Create a new API Key (Sanctum Token).
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            // Scopes and other fields from api_management.md will be added here
        ]);

        // Mocking user for token creation - ideally this should be a system user or the current admin
        $user = auth()->user();
        $token = $user->createToken($request->name);

        return back()->with('success', 'API Key created: ' . $token->plainTextToken . '. Please save it now, as it will not be shown again.');
    }

    /**
     * Revoke an API Key.
     */
    public function destroy(PersonalAccessToken $apiKey)
    {
        $apiKey->delete();
        return back()->with('success', 'API Key revoked.');
    }
}
