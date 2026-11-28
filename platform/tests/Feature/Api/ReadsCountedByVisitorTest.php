<?php

declare(strict_types=1);

/*
| What the shop reads of /api/v1 is counted by the visitor, not by the shop's server (defect D-082).
|
| With the product link on, a visitor's page asks the shop's own server, and that server asks this application.
| Every read of every visitor therefore arrives from one address. The addresses of /api/v1 that are read allow
| one address 120 reads a minute, all of them together, so the whole shop had one visitor's allowance. An open
| page of the shop asks nine times a minute without being touched: about fourteen open pages use the minute up,
| and every page is then told that the catalogue is not available. Measured on 27 November on the rehearsal's
| admin: of 125 reads from one address within a minute, the last five were answered 429.
|
| The cure is the one a basket already has (BasketOfPublishedProductsTest, the last test): a server that holds
| the bridge key names the visitor it asks for, and the count is his. What is held here:
|
|   - each visitor the shop's server names has 120 reads a minute of his own, over all the addresses together;
|   - the answer that says "wait" is the API's own, with how long;
|   - whoever does not hold the key is counted by the address he really has, whatever he writes in the header.
|
| Tried against (release report 47): with the limiter counting by the address the request came from, the first
| test names the second visitor; with the visitor's header read from anybody, the second test names the read
| that should have waited.
*/

beforeEach(function (): void {
    config(['woodex.product_sync_enabled' => true, 'woodex.public_leads.bridge_key' => 'synthetic-bridge-key-of-thirty-two-chars']);

    // Every request of this file arrives from one address: the shop's server.
    $this->read = fn (string $address, array $headers = []) => $this
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.200'])
        ->withHeaders(['Accept' => 'application/json', ...$headers])
        ->get($address);
    $this->for = fn (string $visitor, string $key = 'synthetic-bridge-key-of-thirty-two-chars'): array => ['X-Woodex-Key' => $key, 'X-Woodex-Visitor' => $visitor];
});

it('gives each visitor of the shop 120 reads a minute of his own when the shop\'s server asks for him', function (): void {
    // One visitor: the categories, the products and the replaced photographs share his one allowance.
    foreach (range(1, 40) as $n) {
        ($this->read)('/api/v1/catalogue/categories', ($this->for)('203.0.113.10'))->assertOk();
        ($this->read)('/api/v1/catalogue/products', ($this->for)('203.0.113.10'))->assertOk();
        ($this->read)('/api/v1/media', ($this->for)('203.0.113.10'))->assertOk();
    }
    $wait = ($this->read)('/api/v1/catalogue/categories', ($this->for)('203.0.113.10'));
    $wait->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')->assertHeader('Retry-After');
    expect((int) $wait->headers->get('Retry-After'))->toBeBetween(1, 60);
    ($this->read)('/api/v1/catalogue/products', ($this->for)('203.0.113.10'))->assertStatus(429);

    // Another visitor, through the same server, in the same minute: his own count, untouched by the first.
    ($this->read)('/api/v1/catalogue/categories', ($this->for)('203.0.113.11'))->assertOk();
    ($this->read)('/api/v1/catalogue/products', ($this->for)('203.0.113.11'))->assertOk();
    // The server asking for nobody, and for something that is not an address: counted as itself, which has
    // asked nothing yet.
    ($this->read)('/api/v1/catalogue/categories', ['X-Woodex-Key' => 'synthetic-bridge-key-of-thirty-two-chars'])->assertOk();
    ($this->read)('/api/v1/catalogue/categories', ($this->for)('not-an-address'))->assertOk();
});

it('counts whoever does not hold the key by the address he really has, whatever he names', function (): void {
    // No key, and a new "visitor" named with every read: all of them are the one address that sent them.
    foreach (range(1, 60) as $n) {
        ($this->read)('/api/v1/catalogue/categories', ['X-Woodex-Visitor' => "203.0.113.$n"])->assertOk();
        ($this->read)('/api/v1/catalogue/categories', ($this->for)("203.0.113.$n", 'a-wrong-key-of-a-plausible-length-here'))->assertOk();
    }
    ($this->read)('/api/v1/catalogue/categories', ['X-Woodex-Visitor' => '203.0.113.200'])->assertStatus(429);
    ($this->read)('/api/v1/catalogue/categories', ($this->for)('203.0.113.201', 'a-wrong-key-of-a-plausible-length-here'))->assertStatus(429);

    // While no key is set here, nobody is vouched for: an empty key that is sent proves nothing.
    config(['woodex.public_leads.bridge_key' => '']);
    ($this->read)('/api/v1/catalogue/categories', ($this->for)('203.0.113.202', ''))->assertStatus(429);

    // The holder of the key is not held up by any of that: his visitor has his own count.
    config(['woodex.public_leads.bridge_key' => 'synthetic-bridge-key-of-thirty-two-chars']);
    ($this->read)('/api/v1/catalogue/categories', ($this->for)('203.0.113.203'))->assertOk();
});
