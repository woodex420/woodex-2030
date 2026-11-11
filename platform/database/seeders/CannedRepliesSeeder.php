<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Conversations\Models\CannedReply;
use Illuminate\Database\Seeder;

/**
 * The saved replies the studio sends every day (CNV-004), in English and Roman Urdu. The team edits them
 * in Settings → Canned replies; this seeder only adds what is missing, never overwrites a change.
 */
class CannedRepliesSeeder extends Seeder
{
    public function run(): void
    {
        $replies = [
            ['title' => 'Welcome', 'locale' => 'en', 'keywords' => 'hello, hi, welcome, start', 'body' => 'Assalam-o-alaikum {first_name}, thank you for reaching out to {studio_name}. How can we help you today?'],
            ['title' => 'Studio hours', 'locale' => 'en', 'keywords' => 'hours, timing, open, visit', 'body' => 'Our studio is open {studio_hours}. You are welcome to walk in, or tell us a time and we will be ready for you.'],
            ['title' => 'Quotation link', 'locale' => 'en', 'keywords' => 'quote, quotation, price, link', 'body' => '{first_name}, here is your quotation: {quote_link} — the link always shows the latest version. Any questions, just reply here.'],
            ['title' => 'Visit confirm', 'locale' => 'en', 'keywords' => 'visit, site visit, confirm', 'body' => 'Confirming our visit. We will call you before we come — if the time no longer suits you, tell us here and we will move it.'],
            ['title' => 'On the way', 'locale' => 'en', 'keywords' => 'coming, on the way, arriving', 'body' => 'We are on the way, {first_name}. See you shortly.'],
            ['title' => 'Khush amdeed', 'locale' => 'ur', 'keywords' => 'salam, hello urdu, welcome urdu', 'body' => 'Assalam-o-alaikum {first_name}, {studio_name} se rabta karne ka shukriya. Hum aap ki kya madad kar sakte hain?'],
            ['title' => 'Waqt / hours', 'locale' => 'ur', 'keywords' => 'waqt, timing urdu', 'body' => 'Hamara studio {studio_hours} khula rehta hai. Aap tashreef la sakte hain, ya waqt bata dein hum tayyar rahenge.'],
        ];

        foreach ($replies as $index => $reply) {
            CannedReply::query()->firstOrCreate(
                ['title' => $reply['title']],
                $reply + ['position' => ($index + 1) * 10, 'is_active' => true],
            );
        }
    }
}
