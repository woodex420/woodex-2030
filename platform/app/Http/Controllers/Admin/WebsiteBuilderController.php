<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Website\Builder\BuilderApi;
use App\Domain\Website\Builder\BuilderFile;
use App\Domain\Website\Builder\StalePageException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The Visual Page Builder (v2.1 front-end, Woodex OS back-end).
 *
 * The screen is a full-document HTML app of its own — it owns the viewport, the way the old /builder/
 * did — but it is a normal admin route: the OS session, the `website.edit` policy and the CSRF token
 * guard it. There is no second password and no second user list.
 *
 * It edits the site's real HTML files under `public/` (BuilderFile), not a database copy: the file the web
 * server answers is the file the owner saves.
 */
final class WebsiteBuilderController
{
    /**
     * The builder opens a page file (Woodex OS 1): `?path=about/index.html`. Without one it opens the first
     * page the site has, so the screen is never empty and never guesses at a page that is not there.
     */
    public function show(Request $request): View
    {
        $pages = BuilderApi::pageList();
        $wanted = trim((string) $request->query('path', ''));
        $known = array_column($pages, 'path');

        return view('admin.website.builder', [
            'bootPage' => in_array($wanted, $known, true) ? $wanted : ($known[0] ?? ''),
        ]);
    }

    public function api(Request $request, BuilderApi $api): JsonResponse
    {
        try {
            $action = (string) $request->input('action', 'status');

            $out = $action === 'upload'
                ? $api->upload($request->file('file'), $request->user())
                : $api->handle($action, $request->all(), $request->user());
        } catch (StalePageException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->validator->errors()->first()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false, 'error' => 'Page not found.'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            // Storage the builder cannot work with — a damaged section library, an unwritable folder. The
            // message says what happened and what was not changed; an HTML error page would not reach the panel.
            report($e);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json($out);
    }
}
