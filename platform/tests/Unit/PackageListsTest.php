<?php

use App\Support\Install\PackageLists;

/*
| A release that is copied over the one before brings its own packages, and Composer is not relied on to run on
| the host, so Laravel's lists of the packages it loads can be left from the old release. A list older than the
| packages is dropped when the application starts (master list T-145).
*/

beforeEach(function () {
    $this->folder = sys_get_temp_dir().'/woodex-lists-'.bin2hex(random_bytes(4));
    mkdir($this->folder.'/vendor/composer', 0777, true);
    mkdir($this->folder.'/bootstrap/cache', 0777, true);
    $this->installed = $this->folder.'/vendor/composer/installed.json';
    $this->packages = $this->folder.'/bootstrap/cache/packages.php';
    $this->services = $this->folder.'/bootstrap/cache/services.php';
});

afterEach(function () {
    foreach ([$this->installed, $this->packages, $this->services] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (['/vendor/composer', '/vendor', '/bootstrap/cache', '/bootstrap', ''] as $folder) {
        rmdir($this->folder.$folder);
    }
});

it('drops the lists that are older than the packages, so that Laravel writes them again', function () {
    file_put_contents($this->packages, '<?php return [];');
    file_put_contents($this->services, '<?php return [];');
    file_put_contents($this->installed, '{}');
    touch($this->packages, time() - 3600);
    touch($this->services, time() - 3600);
    touch($this->installed, time() - 60); // a deployment a minute ago brought other packages

    expect(PackageLists::dropWhenStale($this->installed, [$this->packages, $this->services]))->toBe([$this->packages, $this->services])
        ->and(is_file($this->packages))->toBeFalse()
        ->and(is_file($this->services))->toBeFalse();
});

it('keeps the lists that were written after the packages changed', function () {
    file_put_contents($this->installed, '{}');
    file_put_contents($this->packages, '<?php return [];');
    file_put_contents($this->services, '<?php return [];');
    touch($this->installed, time() - 3600);
    touch($this->packages, time() - 60);
    touch($this->services, time() - 3600); // written in the same second as the packages: not older

    expect(PackageLists::dropWhenStale($this->installed, [$this->packages, $this->services]))->toBe([])
        ->and(is_file($this->packages))->toBeTrue()
        ->and(is_file($this->services))->toBeTrue();
});

it('does nothing where there are no lists yet, or no packages to compare with', function () {
    expect(PackageLists::dropWhenStale($this->installed, [$this->packages, $this->services]))->toBe([]);

    file_put_contents($this->packages, '<?php return [];');

    expect(PackageLists::dropWhenStale($this->installed, [$this->packages]))->toBe([])
        ->and(is_file($this->packages))->toBeTrue();
});

it('is asked when the application starts, before Laravel reads its lists', function () {
    $start = (string) file_get_contents(dirname(__DIR__, 2).'/bootstrap/app.php');

    expect($start)->toContain("PackageLists::dropWhenStale(\$app->basePath('vendor/composer/installed.json'), [\$app->getCachedPackagesPath(), \$app->getCachedServicesPath()]);");
});
