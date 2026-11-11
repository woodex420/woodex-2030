<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Crm\Models\Contact;
use App\Domain\Projects\Actions\OpenPortalWithCode;
use App\Domain\Projects\Actions\RequestPortalAccess;
use App\Domain\Projects\Services\PortalLinks;
use App\Settings\BusinessSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The door for a client who has lost their link (PRJ-006): they give us the phone number or the email they booked
 * with, we email six digits, and the code opens their projects. No client password exists anywhere in the system.
 */
final class ClientPortalController
{
    public function form(Request $request, BusinessSettings $business): View
    {
        /** @var Contact|null $contact */
        $contact = $request->session()->has('portal_contact_id')
            ? Contact::query()->with('projects')->find($request->session()->get('portal_contact_id'))
            : null;

        return view('site.portal-access', [
            'contact' => $contact,
            'projects' => $contact !== null ? $contact->projects : collect(),
            'business' => $business,
            'sent' => $request->session()->get('portal_code_sent', false),
        ]);
    }

    public function sendCode(Request $request, RequestPortalAccess $requestAccess): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'identifier' => ['required', 'string', 'max:120'],
        ], [], ['identifier' => 'phone number or email']);

        if ($validator->fails()) {
            return redirect()->route('portal')->withErrors($validator, 'code')->withInput();
        }

        // The answer is the same either way, so the form cannot be used to discover who our clients are.
        $requestAccess->handle((string) $request->input('identifier'), (string) $request->ip());

        return redirect()->route('portal')->with('portal_code_sent', true)->withInput();
    }

    public function enter(Request $request, OpenPortalWithCode $open): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'identifier' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'size:6'],
        ], [], ['identifier' => 'phone number or email', 'code' => 'code']);

        if ($validator->fails()) {
            return redirect()->route('portal')->withErrors($validator, 'code')->withInput();
        }

        $contact = $open->handle((string) $request->input('identifier'), (string) $request->input('code'));

        if ($contact === null) {
            return redirect()->route('portal')
                ->withErrors(['code' => 'That code is not right, or it has expired. Ask for a new one.'], 'code')
                ->withInput();
        }

        $request->session()->put('portal_contact_id', $contact->id);

        $projects = $contact->projects()->get();

        // One project goes straight in; more than one is a choice.
        return $projects->count() === 1
            ? redirect()->to(url(PortalLinks::signed('projects.portal', $projects->first())))
            : redirect()->route('portal');
    }

    public function leave(Request $request): RedirectResponse
    {
        $request->session()->forget('portal_contact_id');

        return redirect()->route('portal');
    }
}
