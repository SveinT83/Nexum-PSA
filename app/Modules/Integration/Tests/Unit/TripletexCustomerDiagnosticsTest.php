<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Provider content is untrusted even when its field name is recognized. */
class TripletexCustomerDiagnosticsTest extends TestCase
{
    private function errors(array $body): array
    {
        $response = new Response(new PsrResponse(422, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));

        return (new ReflectionMethod(TripletexClient::class, 'customerFieldErrors'))->invoke(new TripletexClient(new Integration), $response);
    }

    public function test_diagnostics_are_bounded_and_never_echo_values_or_arbitrary_text(): void
    {
        $messages = array_fill(0, 500, ['field' => 'invoiceEmail', 'message' => 'SECRET user content', 'value' => 'SECRET value']);
        $messages[20] = ['field' => 'name', 'message' => 'Required for EHF'];
        $this->assertSame(['billing_email' => ['Tripletex rejected this field. Review the supplied value.']],
            $this->errors(['validationMessages' => $messages, 'developerMessage' => 'SECRET trace']));
    }

    public function test_non_list_and_malformed_messages_cannot_invent_public_fields(): void
    {
        foreach ([null, 'SECRET', ['field' => 'name', 'message' => 'Required for EHF'],
            [null, ['field' => 'token', 'message' => 'SECRET'], ['field' => ['name'], 'message' => 'SECRET'],
                ['field' => 'name', 'message' => ['SECRET']]]] as $messages) {
            $this->assertSame([], $this->errors(['validationMessages' => $messages]));
        }
    }
}
