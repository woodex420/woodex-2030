<?php

use App\Support\BusinessHours;
use Carbon\CarbonImmutable;

$hours = new BusinessHours('10:00', '19:00', [1, 2, 3, 4, 5, 6]);

it('gives 15 minutes inside studio hours', function () use ($hours) {
    $monday = CarbonImmutable::parse('2026-10-19 11:00', 'Asia/Karachi');

    expect($hours->dueAt($monday, 15)->format('Y-m-d H:i'))->toBe('2026-10-19 11:15');
});

it('rolls late-evening and Sunday enquiries to the next opening', function () use ($hours) {
    $saturdayNight = CarbonImmutable::parse('2026-10-24 22:30', 'Asia/Karachi');
    $sunday = CarbonImmutable::parse('2026-10-25 12:00', 'Asia/Karachi');
    $nearClosing = CarbonImmutable::parse('2026-10-19 18:55', 'Asia/Karachi');

    expect($hours->dueAt($saturdayNight, 15)->format('D H:i'))->toBe('Mon 10:30')
        ->and($hours->dueAt($sunday, 15)->format('D H:i'))->toBe('Mon 10:30')
        ->and($hours->dueAt($nearClosing, 15)->format('D H:i'))->toBe('Tue 10:15');
});

it('names the working days the way the footer prints them', function () {
    expect((new BusinessHours(workingDays: [1, 2, 3, 4, 5, 6]))->daysLabel())->toBe('Monday to Saturday')
        ->and((new BusinessHours(workingDays: [1, 2, 3, 4, 5, 6, 7]))->daysLabel())->toBe('Every day')
        ->and((new BusinessHours(workingDays: [1, 3, 5]))->daysLabel())->toBe('Monday, Wednesday, Friday')
        ->and((new BusinessHours(workingDays: [6]))->daysLabel())->toBe('Saturday')
        ->and((new BusinessHours(workingDays: []))->daysLabel())->toBe('By appointment');
});
