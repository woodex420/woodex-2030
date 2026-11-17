<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Hosts the ported v2.1 admin screens inside Woodex OS (docs/audit/16.7, 16.8).
 *
 * One URL per screen, full document — the same exception the page builder already has, because these
 * screens bring their own stylesheet and their own layout and must not fight the OS theme. The OS
 * session, not theirs, decides who is here.
 */
final class WebsiteStudioController
{
    /**
     * Screens that are ported and switched on. A screen is added here only when its actions work.
     *
     * Service pages, City pages, FAQs, Testimonials and Client logos are retired (Woodex OS 1, §16.19): they
     * only wrote database rows, and the site is the v1 HTML files now. Their work is done in the page builder,
     * and the logos live in the home page's clients section and in Media.
     */
    public const array SCREENS = [
        'blog' => ['title' => 'Blog & insights', 'view' => 'blog'],
        'portfolio' => ['title' => 'Portfolio', 'view' => 'portfolio'],
        'forms' => ['title' => 'Forms', 'view' => 'forms'],
        'estimator' => ['title' => 'Estimator', 'view' => 'estimator'],
    ];

    public function show(Request $request, string $screen): View
    {
        abort_unless(isset(self::SCREENS[$screen]), 404);

        /** @var User $user */
        $user = $request->user();

        $definition = self::SCREENS[$screen];

        return view('admin.website.studio', [
            'screen' => $definition['view'],
            'title' => $definition['title'],
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->hasRole('owner') ? 'owner' : 'admin',
            ],
        ]);
    }
}
