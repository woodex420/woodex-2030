<?php

declare(strict_types=1);

namespace App\Domain\Website\Enums;

/**
 * Page types of the public website (docs/09 §3). The type decides the default template, the schema, and which
 * sections the editor offers first.
 */
enum PageType: string
{
    case Home = 'home';
    case Service = 'service';
    case City = 'city';
    case Area = 'area';
    case Project = 'project';
    case Insight = 'insight';
    case Listing = 'listing';
    case About = 'about';
    case Contact = 'contact';
    case Estimator = 'estimator';
    case Booking = 'booking';
    case Legal = 'legal';
    /** A free-form page built in the Visual Page Builder (HTML page mode). */
    case Page = 'page';

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Home',
            self::Service => 'Service',
            self::City => 'City',
            self::Area => 'Area',
            self::Project => 'Project',
            self::Insight => 'Insight',
            self::Listing => 'Index page',
            self::About => 'About',
            self::Contact => 'Contact',
            self::Estimator => 'Estimator',
            self::Booking => 'Booking',
            self::Legal => 'Legal',
            self::Page => 'Page',
        };
    }

    /** Schema.org type used for this page when the page does not override it (docs/09 §11.2). */
    public function schemaType(): string
    {
        return match ($this) {
            self::Insight => 'Article',
            self::Project => 'CreativeWork',
            default => 'WebPage',
        };
    }
}
