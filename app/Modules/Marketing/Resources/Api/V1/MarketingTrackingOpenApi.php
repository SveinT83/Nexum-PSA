<?php

namespace App\Modules\Marketing\Resources\Api\V1;

use OpenApi\Attributes as OA;

/** Existing public recipient capabilities retain their URLs and response media types. */
#[OA\Get(path: '/marketing/o/{token}', operationId: 'marketing_public_open', tags: ['Marketing Tracking'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Non-cacheable tracking pixel', content: new OA\MediaType(mediaType: 'image/gif', schema: new OA\Schema(type: 'string', format: 'binary')))])]
#[OA\Get(path: '/marketing/c/{token}/{url}', operationId: 'marketing_public_click', tags: ['Marketing Tracking'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'url', in: 'path', required: true, schema: new OA\Schema(type: 'string', description: 'Existing base64-encoded target URL'))], responses: [new OA\Response(response: 302, description: 'Existing tracked destination redirect', headers: [new OA\Header(header: 'Location', schema: new OA\Schema(type: 'string'))])])]
#[OA\Get(path: '/marketing/unsubscribe/{token}', operationId: 'marketing_public_unsubscribe', tags: ['Marketing Tracking'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Unsubscribe/suppression applied for an existing recipient capability', content: new OA\MediaType(mediaType: 'text/plain', schema: new OA\Schema(type: 'string')))])]
class MarketingTrackingOpenApi {}
