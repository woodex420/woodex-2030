<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('description_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('business_line', 20)->index();
            $table->string('section', 80)->index();
            $table->string('title', 100);
            $table->string('body', 500);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
        Schema::create('document_designs', function (Blueprint $table): void {
            $table->id();
            $table->string('business_line', 20)->unique();
            $table->unsignedInteger('revision')->default(1);
            $table->string('brand_name', 35);
            $table->string('accent_color', 7);
            $table->string('header_text', 100)->default('');
            $table->string('footer_text', 120)->default('');
            $table->mediumText('logo_data_uri')->nullable();
            $table->string('logo_sha256', 64)->nullable();
            $table->timestamps();
        });
        foreach (['interior' => ['Woodex Interior', '#0f1e36'], 'furniture' => ['Woodex Furniture', '#276749']] as $line => [$brand, $color]) {
            DB::table('document_designs')->insert(['business_line' => $line, 'brand_name' => $brand, 'accent_color' => $color, 'header_text' => '', 'footer_text' => '', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $starters = [
            ['interior', 'Design scope', 'Interior design deliverables', 'Interior design scope: site assessment, space planning, concept development and agreed drawings. Final deliverables, revision allowance and site visits must be confirmed in the project scope.'],
            ['interior', 'Site and preliminaries', 'Site preparation', 'Site preparation, protection of retained finishes and coordination of work access. Confirm working hours, access restrictions, debris removal and exclusions before pricing.'],
            ['interior', 'Joinery', 'Kitchen joinery', 'Kitchen joinery to approved layout and site measurements. Confirm carcass and shutter materials, thickness, finish, hardware, countertop, accessories and installation scope before pricing.'],
            ['interior', 'Joinery', 'Wardrobe joinery', 'Wardrobe joinery to approved drawings and site measurements. Confirm dimensions, board specification, shutter finish, internal layout, hinges or sliding system, accessories and installation scope.'],
            ['interior', 'Fit out', 'Office / clinic fit out', 'Fit out to approved drawings and measured quantities. Coordinate partitions, ceilings, flooring, electrical, plumbing and joinery. Confirm specialist systems, authority approvals and commissioning exclusions.'],
            ['interior', 'Renovation', 'Refurbishment works', 'Refurbishment following site survey. Identify retained finishes, demolition, making good, material and labor scope, site protection, waste removal and provisional allowances.'],
            ['interior', 'Architecture', 'Architectural services', 'Architectural services to agreed brief and plot/site information. Confirm planning, elevations, drawing stages, consultant coordination, approval responsibilities and site-visit allowance.'],
            ['furniture', 'Desks and tables', 'Executive table specification', 'Executive table to approved dimensions and layout. Confirm top and carcass material, thickness, finish, edge treatment, storage, cable management and hardware. Delivery and assembly scope to be agreed.'],
            ['furniture', 'Seating', 'Executive / task chair specification', 'Chair to approved model and finish. Confirm frame/base, upholstery, cushioning, armrests, tilt and height adjustment, casters and warranty. Dimensions and approved product image to be reviewed before issue.'],
            ['furniture', 'Seating', 'Visitor chair specification', 'Visitor chair to approved model, dimensions and finish. Confirm frame, upholstery, cushioning, armrests and floor protection. Confirm delivery quantity and assembly requirements.'],
            ['furniture', 'Sofas', 'Sofa specification', 'Sofa to approved seating configuration and dimensions. Confirm frame, cushioning, upholstery, stitching, legs and finish. Confirm access, delivery and installation requirements before pricing.'],
            ['furniture', 'Delivery and assembly', 'Furniture delivery / assembly', 'Furniture delivery and assembly at the agreed site. Confirm transport, floor/lift access, protection, installation, packaging removal and handover; identify any additional access or lifting charges.'],
        ];
        foreach ($starters as [$line, $section, $title, $body]) {
            DB::table('description_templates')->insert(['business_line' => $line, 'section' => $section, 'title' => $title, 'body' => $body, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
};
