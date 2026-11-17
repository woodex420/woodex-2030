<?php

declare(strict_types=1);

namespace App\Domain\Projects\Services;

use App\Domain\Projects\Models\Project;
use App\Settings\BusinessSettings;
use Illuminate\Support\Facades\URL;

/**
 * The client's door into a project (PRJ-006): one signed link, no password, valid for as long as the job runs.
 * The same pattern as the quotation and invoice links (D-60): the client never creates an account.
 */
final class PortalLinks
{
    /** Days a portal link stays valid: long enough to outlive the job, short enough to be retired. */
    private const int DAYS = 120;

    /**
     * The link as the app writes it (relative, so it survives a domain change).
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function signed(string $route, Project $project, array $parameters = []): string
    {
        return URL::temporarySignedRoute(
            $route,
            now()->addDays(self::DAYS),
            array_merge(['project' => $project->public_id], $parameters),
            absolute: false,
        );
    }

    public static function portal(Project $project): string
    {
        return self::signed('projects.portal', $project);
    }

    /**
     * The link to give a client: on a screen, in an e-mail, in a WhatsApp message.
     *
     * The signature is made over the path, as the page checks it (`signed:relative`), and the site's address is
     * put in front afterwards. It used to be made over the whole address, which the page refused: every link
     * that was shown, copied, e-mailed or shared answered "No access" (defect D-025).
     */
    public static function absolute(Project $project): string
    {
        return url(self::portal($project));
    }

    /**
     * The same link, ready to paste into WhatsApp: "the lounge project: …".
     */
    public static function whatsappShare(Project $project): string
    {
        $business = app(BusinessSettings::class);

        return 'https://wa.me/?text='.rawurlencode(
            $project->code.': '.$project->name."\n".self::absolute($project)."\n".$business->brand_name
        );
    }
}
