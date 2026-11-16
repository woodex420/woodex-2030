<?php

return [
    // Set only after the owner reviews identity, terms, signers AND built-in document defaults.
    // This is an operator attestation, not evidence that the owner has approved anything.
    'owner_confirmed' => (bool) env('DOCUMENTS_OWNER_CONFIRMED', false),
    // Keeps inherited workflow tests focused. Never honored outside an actual testing environment.
    'test_bypass' => (bool) env('DOCUMENTS_TEST_BYPASS', false),
];
