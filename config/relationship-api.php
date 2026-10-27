<?php

/** Installing API support never activates outbound content export. */
return ['outbound_execution_enabled' => (bool) env('RELATIONSHIP_API_OUTBOUND_EXECUTION_ENABLED', false)];
