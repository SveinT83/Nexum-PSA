<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use L5Swagger\ConfigFactory;
use Laravel\Sanctum\PersonalAccessToken;

class ApiController extends Controller
{
    /**
     * Display the API management dashboard.
     */
    public function index(ApiAbilityCatalog $abilityCatalog)
    {
        $apiKeys = PersonalAccessToken::all();
        $tokensRequiringReview = $apiKeys->filter(fn (PersonalAccessToken $token): bool => $abilityCatalog->requiresReview($token->abilities ?? []));

        return view('integration::Tech.Admin.System.Integrations.api.index', [
            'apiKeys' => $apiKeys,
            'tokensRequiringReview' => $tokensRequiringReview,
            'abilityCatalog' => $abilityCatalog,
            'abilities' => $abilityCatalog->all(),
        ]);
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
    public function store(Request $request, ApiAbilityCatalog $abilityCatalog)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['exclude_if:full_access,1', 'required_unless:full_access,1', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', $abilityCatalog->values())],
            'full_access' => ['nullable', 'boolean'],
            'confirm_full_access' => ['exclude_unless:full_access,1', 'required_if:full_access,1', 'accepted'],
        ]);

        $user = auth()->user();
        $abilities = $abilityCatalog->normalize(
            (array) ($validated['abilities'] ?? []),
            $request->boolean('full_access'),
        );
        $token = $user->createToken($validated['name'], $abilities);

        return back()->with('success', 'API Key created: '.$token->plainTextToken.'. Please save it now, as it will not be shown again.');
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
