<?php

use App\Support\JsonObjects;

it('ignores only JSON object key order, including nested objects', function () {
    expect(JsonObjects::canonical(['b' => ['y' => 2, 'x' => 1], 'a' => 0]))
        ->toBe(JsonObjects::canonical(['a' => 0, 'b' => ['x' => 1, 'y' => 2]]));
});

it('preserves JSON list order and scalar types for financial snapshots', function () {
    expect(JsonObjects::canonical([1, 2]))->not->toBe(JsonObjects::canonical([2, 1]));
    expect(JsonObjects::canonical(['minor' => 100]))->not->toBe(JsonObjects::canonical(['minor' => '100']));
    expect(JsonObjects::canonical(['minor' => 100]))->not->toBe(JsonObjects::canonical(['minor' => 101]));
});
