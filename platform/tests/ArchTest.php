<?php

arch('no debugging statements')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('application code uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('domain code does not depend on Livewire or HTTP controllers')
    ->expect('App\Domain')
    ->not->toUse(['Livewire', 'App\Http\Controllers', 'App\Livewire']);
